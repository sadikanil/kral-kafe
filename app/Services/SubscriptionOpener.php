<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ogrenciye paket donemi acar (Dalga 7; Dalga 20'de ortak yere alindi).
 *
 * Iki yer kullaniyor: odeme ekranindan paket atama ve yeni ogrenci ekleme
 * akisi. Ayni kurali iki yerde tutmak, birinin gunun birinde fiyati
 * kopyalamayi unutmasi demekti.
 */
class SubscriptionOpener
{
    public function open(
        User $student,
        Package $package,
        Carbon $starts,
        ?Carbon $ends = null,
        ?string $price = null,
        ?string $note = null,
    ): Subscription {
        $ends ??= $starts->copy()->addMonthNoOverflow()->subDay();

        $abonelik = Subscription::create([
            'student_id' => $student->id,
            'package_id' => $package->id,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            // Fiyat KOPYALANIR: katalog sonradan degisse de bu satir sabit.
            'price' => $price ?? $package->monthly_price,
            'note' => $note,
            'created_by' => auth()->id(),
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
     * Paketi bir tarihten itibaren degistirir (Dalga 30a, karar 23 Eyl).
     *
     * Eski ana paket bir gun once biter; yenisi o gun baslar ve eskinin
     * bitis gununu devralir - odenmis donem yeni paketle surer. Degisim
     * eskinin ilk gunundeyse eski hic kullanilmamistir: kisaltmak yerine
     * iptal edilir (bitis < baslangic olan bir satir birakmamak icin).
     * Ek paketlere dokunulmaz.
     */
    public function switch(User $student, Package $package, Carbon $on, ?string $price = null): Subscription
    {
        $eski = $student->subscriptions()->activeOn($on->toDateString())
            ->whereHas('package', fn ($q) => $q->where('is_addon', false))
            ->orderByDesc('starts_on')->first();

        $bitis = null;
        if ($eski !== null) {
            $bitis = $eski->ends_on->copy();

            if ($eski->starts_on->greaterThanOrEqualTo($on->copy()->startOfDay())) {
                $eski->forceFill(['payment_status' => \App\Enums\PaymentStatus::Cancelled])->save();
            } else {
                $eski->update(['ends_on' => $on->copy()->subDay()->toDateString()]);
            }
        }

        return $this->open($student, $package, $on, $bitis, $price, 'Paket değişikliği');
    }
}
