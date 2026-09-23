<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
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
    public function overview(Request $request)
    {
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

    public function index(User $user)
    {
        abort_unless($user->isStudent(), 404);

        $abonelikler = Subscription::where('student_id', $user->id)
            ->with(['package', 'payments'])
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
        ]);

        $paket = Package::findOrFail($veri['package_id']);
        $bas = Carbon::parse($veri['starts_on']);
        $bit = isset($veri['ends_on']) ? Carbon::parse($veri['ends_on']) : null;

        $acildi = DB::transaction(function () use ($user, $paket, $bas, $bit, $veri) {
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
                return false;
            }

            app(\App\Services\SubscriptionOpener::class)
                ->open($user, $paket, $bas, $bit, isset($veri['price']) ? (string) $veri['price'] : null, $veri['note'] ?? null);

            return true;
        });

        return redirect()->route('admin.subscriptions.index', $user)->with('success', $acildi
            ? 'Paket atandı.'
            : 'Bu paket bu tarihten itibaren zaten atanmış; ikinci kez açılmadı.');
    }

    /** Dalga 30a: paketi tarihten itibaren degistir (kullanici sayfasindan). */
    public function switch(Request $request, User $user, \App\Services\SubscriptionOpener $abonelikler)
    {
        abort_unless($user->isStudent(), 404);

        $veri = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', 1)->where('is_addon', 0)],
            'switch_on' => ['required', 'date_format:Y-m-d'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], ['package_id.exists' => 'Ek paket ana paketin yerine geçemez; ödeme ekranından ekleyin.']);

        DB::transaction(fn () => $abonelikler->switch(
            $user,
            Package::findOrFail($veri['package_id']),
            Carbon::parse($veri['switch_on']),
            isset($veri['price']) ? (string) $veri['price'] : null,
        ));

        return back()->with('success', 'Paket değiştirildi.');
    }

    public function cancel(Subscription $subscription)
    {
        $subscription->forceFill(['payment_status' => PaymentStatus::Cancelled])->save();

        return back()->with('success', 'Abonelik iptal edildi. Öğrencinin abonelik durumu değiştirilmedi; gerekirse kullanıcı formundan kapatın.');
    }

    public function storePayment(Request $request, Subscription $subscription)
    {
        if ($subscription->isCancelled()) {
            return back()->with('error', 'İptal edilmiş aboneliğe ödeme yazılamaz.');
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
