<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionOpener;
use App\Services\SubscriptionRenewer;
use App\Support\LocalDay;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Ogrenciye paket atama, odeme kaydi ve odeme takibi.
 *
 * Fiyat atama aninda pakete gore onerilir ama abonelige KOPYALANIR
 * (subscriptions.price); sonraki katalog degisikligi bu satiri etkilemez.
 */
class SubscriptionController extends Controller
{
    /** Odeme takibi: yururlukteki ve bekleyen/gecikmis abonelikler. */
    public function overview(Request $request, SubscriptionRenewer $yenileyici)
    {
        // Gece isi kacmis olsa da liste yenilenmis donemlerle acilsin.
        $yenileyici->renewDue();

        $durum = $request->query('durum');

        $abonelikler = Subscription::with(['student', 'package', 'payments'])
            ->orderByDesc('starts_on')
            ->limit(200)
            ->get()
            ->each->syncPaymentStatus();

        if ($durum && PaymentStatus::tryFrom($durum)) {
            $abonelikler = $abonelikler->where('payment_status', PaymentStatus::from($durum));
        }

        return view('admin.subscriptions.overview', [
            'subscriptions' => $abonelikler->values(),
            'filter' => $durum,
            'counts' => Subscription::query()
                ->selectRaw('payment_status, count(*) as adet')
                ->groupBy('payment_status')
                ->pluck('adet', 'payment_status'),
        ]);
    }

    public function index(User $user, SubscriptionRenewer $yenileyici)
    {
        abort_unless($user->isStudent(), 404);

        $yenileyici->renewForStudent($user);

        $abonelikler = Subscription::where('student_id', $user->id)
            ->with(['package', 'payments', 'nextPackage', 'renewal'])
            ->orderByDesc('starts_on')
            ->get()
            ->each->syncPaymentStatus();

        return view('admin.subscriptions.index', [
            'student' => $user,
            'subscriptions' => $abonelikler,
            'packages' => Package::active()->orderBy('name')->get(),
            'defaultStart' => LocalDay::today(),
        ]);
    }

    public function store(Request $request, User $user)
    {
        abort_unless($user->isStudent(), 404);

        $veri = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'auto_renew' => ['nullable', 'boolean'],
        ]);

        $paket = Package::findOrFail($veri['package_id']);
        $bas = Carbon::parse($veri['starts_on']);
        $bit = isset($veri['ends_on']) ? Carbon::parse($veri['ends_on']) : null;
        $yenile = $request->boolean('auto_renew', true);

        $acildi = DB::transaction(function () use ($user, $paket, $bas, $bit, $veri, $yenile) {
            // "Paketi ata"ya iki kez basilinca (yavas mobil baglanti) ayni
            // donem iki kez aciliyor, ogrenci iki kez borclaniyordu. Ogrenci
            // satiri kilitlenir: ayni anda gelen iki istek sirayla bakar
            // (Postgres; SQLite yazmalari zaten sirali). Iptal edilmis donem
            // sayilmaz - "iptal et, yeniden ata" calismali.
            User::whereKey($user->id)->lockForUpdate()->first();

            $zatenVar = Subscription::where('student_id', $user->id)
                ->where('package_id', $paket->id)
                ->whereDate('starts_on', $bas->toDateString())
                ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
                ->exists();

            if ($zatenVar) {
                return 'ayni';
            }

            // Ayni tarihlerde ikinci bir ana paket uyeye iki kez borc yazar
            // (canlida ayni gun baslayan iki ana paket vardi). Ek paket ana
            // paketle cakisabilir, kendisiyle cakisamaz.
            $bitis = ($bit ?? $bas->copy()->addMonthNoOverflow()->subDay())->toDateString();
            $cakisan = Subscription::where('student_id', $user->id)
                ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
                ->whereDate('starts_on', '<=', $bitis)
                ->whereDate('ends_on', '>=', $bas->toDateString())
                ->when(
                    $paket->is_addon,
                    fn ($q) => $q->where('package_id', $paket->id),
                    fn ($q) => $q->whereHas('package', fn ($p) => $p->where('is_addon', false)),
                )
                ->exists();

            if ($cakisan) {
                return 'cakisma';
            }

            return app(SubscriptionOpener::class)
                ->open($user, $paket, $bas, $bit, isset($veri['price']) ? (string) $veri['price'] : null, $veri['note'] ?? null, $yenile);
        });

        if ($acildi instanceof Subscription) {
            $donem = $acildi->starts_on->format('d.m') . '–' . $acildi->ends_on->format('d.m.Y');
            $kist = $acildi->isPartialMonth() ? ' (kıst)' : '';

            return redirect()->route('admin.subscriptions.index', $user)->with('success',
                "Paket atandı: {$donem} için {$acildi->formattedPrice()}{$kist}. "
                . ($yenile ? "Her ayın 1'inde yenilenecek." : 'Dönem sonunda bitecek.'));
        }

        return match ($acildi) {
            'ayni' => redirect()->route('admin.subscriptions.index', $user)
                ->with('success', 'Bu paket bu tarihten itibaren zaten atanmış; ikinci kez açılmadı.'),
            default => back()->withInput()->with('error', $paket->is_addon
                ? 'Bu ek paket bu tarihlerde zaten var.'
                : 'Bu tarihlerde zaten bir ana paketi var. Değiştirmek için kullanıcı sayfasındaki "Paketi değiştir"i kullanın.'),
        };
    }

    /**
     * Paketi degistir (kullanici sayfasindan). 18 Kas 2026: tarihten itibaren
     * yeni tam fiyatli donem acmak yerine "sonraki donemden" ya da "bu
     * donemden"; bkz. SubscriptionOpener::changePackage.
     */
    public function switch(Request $request, User $user, SubscriptionOpener $abonelikler)
    {
        abort_unless($user->isStudent(), 404);

        $veri = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', 1)->where('is_addon', 0)],
            'when' => ['required', Rule::in(['sonraki', 'simdi'])],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], ['package_id.exists' => 'Ek paket ana paketin yerine geçemez; ödeme ekranından ekleyin.']);

        $mesaj = DB::transaction(function () use ($user, $veri, $abonelikler) {
            User::whereKey($user->id)->lockForUpdate()->first();

            return $abonelikler->changePackage(
                $user,
                Package::findOrFail($veri['package_id']),
                $veri['when'],
                isset($veri['price']) ? (string) $veri['price'] : null,
            )[1];
        });

        return back()->with('success', $mesaj);
    }

    /** Yenilemeyi durdur / yeniden ac (18 Kas 2026). Donemin kendisine dokunmaz. */
    public function renewal(Request $request, Subscription $subscription)
    {
        $ac = $request->boolean('auto_renew');

        if (! $subscription->canToggleRenewal()) {
            return back()->with('error', 'Bu dönem için yenileme değiştirilemez. Biten ya da yerine yenisi gelmiş paket için paketi yeniden atayın.');
        }

        $subscription->forceFill($ac ? ['auto_renew' => true] : ['auto_renew' => false, 'next_package_id' => null, 'next_price' => null])->save();

        return back()->with('success', $ac
            ? 'Paket her ay yenilenecek; yeni dönem ' . $subscription->renewsOn()->format('d.m.Y') . ' tarihinde başlar.'
            : 'Yenileme durduruldu; paket ' . $subscription->ends_on->format('d.m.Y') . ' tarihinde bitecek.');
    }

    public function cancel(Subscription $subscription)
    {
        // Iptal edilen donem yenilenmez; bekleyen "sonraki donem" secimi de duser.
        $subscription->forceFill([
            'payment_status' => PaymentStatus::Cancelled,
            'auto_renew' => false,
            'next_package_id' => null,
            'next_price' => null,
        ])->save();

        return back()->with('success', 'Üyelik iptal edildi. Bu dönem yenilenmeyecek; üyenin üyelik durumu değiştirilmedi, gerekirse kullanıcı formundan kapatın.');
    }

    public function storePayment(Request $request, Subscription $subscription)
    {
        if ($subscription->isCancelled()) {
            return back()->with('error', 'İptal edilmiş üyeliğe ödeme yazılamaz.');
        }

        $veri = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($subscription, $veri) {
            // "Odeme kaydet"e iki kez basilinca ayni odeme iki kez yaziliyor,
            // kalan yanlis dusuyor, abonelik "Odendi"ye donebiliyordu. Tablo
            // benzersizlik tasiyamaz (iki esit taksit mesrudur); bu yuzden
            // AYNI yoneticinin bir dakika icinde gonderdigi AYNI odeme elenir
            // (yavas baglantida ikinci dokunus saniyeler sonra gelir; ayni
            // tutarli gercek ikinci taksit dakikalar sonra yazilir).
            // Abonelik satiri kilitlenir: esanli iki istek sirayla bakar.
            Subscription::whereKey($subscription->id)->lockForUpdate()->first();

            $ayniOdeme = $subscription->payments()
                ->where('amount', $veri['amount'])
                ->whereDate('paid_at', $veri['paid_at'])
                ->where('method', $veri['method'])
                ->where('note', $veri['note'] ?? null)
                ->where('recorded_by', auth()->id())
                ->where('created_at', '>=', now()->subMinute())
                ->exists();

            if (! $ayniOdeme) {
                $subscription->payments()->create($veri + ['recorded_by' => auth()->id()]);
            }
        });
        $subscription->syncPaymentStatus();

        return back()->with('success', 'Ödeme kaydedildi. Kalan: ' . $subscription->formattedBalance());
    }

    public function destroyPayment(Payment $payment)
    {
        $abonelik = $payment->subscription;
        $payment->delete();
        $abonelik->syncPaymentStatus();

        return back()->with('success', 'Ödeme kaydı silindi.');
    }
}
