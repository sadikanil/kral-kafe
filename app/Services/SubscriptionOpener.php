<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * Ogrenciye paket donemi acar (Dalga 7; Dalga 20'de ortak yere alindi).
 *
 * Uc yer kullaniyor: odeme ekranindan paket atama, yeni ogrenci ekleme
 * akisi ve aylik yenileme (SubscriptionRenewer). Ayni kurali birkac yerde
 * tutmak, birinin gunun birinde fiyati kopyalamayi unutmasi demekti.
 */
class SubscriptionOpener
{
    /**
     * $price uyenin AYLIK tutaridir (bos: paket fiyati). Bitis verilmezse
     * donem takvim ayinin sonunda biter ve tutar kalan gunlere orantilanir
     * (21 Kas 2026: paket ayin basinda yenilenir). Bitis verilirse yonetimin
     * ozel donemidir: tutar yazildigi gibi.
     */
    public function open(
        User $student,
        Package $package,
        Carbon $starts,
        ?Carbon $ends = null,
        ?string $price = null,
        ?string $note = null,
        bool $autoRenew = true,
        ?Subscription $renewedFrom = null,
    ): Subscription {
        $aylik = $price ?? (string) $package->monthly_price;
        $tutar = $ends === null
            ? Subscription::priceFor($aylik, $starts, $ends = Subscription::monthEndFor($starts))
            : $aylik;

        $abonelik = Subscription::create([
            'student_id' => $student->id,
            'package_id' => $package->id,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            // Fiyat KOPYALANIR: katalog sonradan degisse de bu satir sabit.
            'price' => $tutar,
            'base_price' => $aylik,
            'note' => $note,
            // Yenilemeyi sistem acar; uyenin kendi sayfasi tetiklese de
            // "acan" o uye degildir.
            'created_by' => $renewedFrom ? null : auth()->id(),
            'auto_renew' => $autoRenew,
            'renewed_from_id' => $renewedFrom?->id,
        ]);
        $abonelik->syncPaymentStatus();

        // Ek paket ana paketin tarihlerini ezmesin: abonelik kapisi
        // users.subscription_status'a bakiyor, tarihler ana paketten gelir.
        if (! $package->is_addon) {
            $student->update([
                'subscription_status' => 'active',
                'subscription_start' => $starts->toDateString(),
                'subscription_end' => $ends->toDateString(),
            ]);
        }

        return $abonelik;
    }

    /**
     * Ana paketi degistirir (18 Kasim 2026; eski "tarihten itibaren" akisinin
     * yerine).
     *
     * Eski akis yeni paketi TAM fiyatli yeni bir donem olarak aciyordu; ay
     * sonunu uzatmak icin de kullanildigindan canlida birkac gunluk tam
     * fiyatli parcalar birikti. Artik iki secenek var, ikisi de yeni satir
     * acmaz:
     *
     * - 'sonraki' (varsayilan): bu donem aynen kalir; yenilemede yeni paket
     *   (ve tutar) gecerli olur. Donem yenilenmiyorduysa yenilemeye alinir.
     * - 'simdi': bu donemin paketi ve tutari yerinde degisir (yanlis atama
     *   duzeltmesi, ay icinde yukseltme). Odenen tutar korunur; fark kalan
     *   olarak gorunur.
     *
     * Yururlukte paketi yoksa ikisi de bugunden ay sonuna (kist) yeni donem
     * acar; ayin 1'inde yenilenir.
     * Ek paketlere dokunulmaz.
     *
     * @return array{0: Subscription, 1: string} donem ve yoneticiye mesaj
     */
    public function changePackage(User $student, Package $package, string $when, ?string $price = null): array
    {
        $bugun = LocalDay::today();
        $simdiki = $student->subscriptions()->activeOn($bugun)
            ->whereHas('package', fn ($q) => $q->where('is_addon', false))
            ->with('package')->orderByDesc('starts_on')->orderByDesc('id')->first();

        if ($simdiki === null) {
            $yeni = $this->open($student, $package, Carbon::parse($bugun), null, $price, 'Paket değişikliği');

            return [$yeni, $package->name . ' bugünden itibaren açıldı; her ayın 1\'inde yenilenecek.'];
        }

        if ($when === 'simdi') {
            $aylik = $price ?? (string) $package->monthly_price;
            $simdiki->forceFill([
                'package_id' => $package->id,
                // Kist donemde yeni aylik tutar da gun orantisiyla.
                'price' => Subscription::priceFor($aylik, $simdiki->starts_on, $simdiki->ends_on),
                'base_price' => $aylik,
                'next_package_id' => null,
                'next_price' => null,
                'auto_renew' => true,
                'note' => mb_substr(ltrim($simdiki->note . ' · Paket ' . Carbon::parse($bugun)->format('d.m') . ' tarihinde değişti', ' ·'), 0, 255),
            ])->save();
            $simdiki->unsetRelation('package');
            $simdiki->syncPaymentStatus();

            return [$simdiki, 'Bu dönemin paketi ' . $package->name . ' oldu. Kalan: ' . $simdiki->formattedBalance()];
        }

        // Zincirin ucu: ileri tarihli bir donem zaten atanmissa yenilenecek
        // olan odur; secim oraya yazilmazsa sessizce bosa giderdi.
        $simdiki = $student->subscriptions()
            ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->whereDate('ends_on', '>=', $bugun)
            ->whereHas('package', fn ($q) => $q->where('is_addon', false))
            ->orderByDesc('starts_on')->orderByDesc('id')->first() ?? $simdiki;

        $ayni = $package->id === $simdiki->package_id && ($price === null || (float) $price === (float) $simdiki->monthlyPrice());
        $simdiki->forceFill([
            'next_package_id' => $ayni ? null : $package->id,
            'next_price' => $ayni ? null : $price,
            'auto_renew' => true,
        ])->save();

        return [$simdiki, $package->name . ', ' . $simdiki->renewsOn()->format('d.m.Y') . ' tarihli yeni dönemden itibaren geçerli.'];
    }
}
