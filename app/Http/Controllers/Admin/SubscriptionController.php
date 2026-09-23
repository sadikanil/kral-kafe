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

        DB::transaction(fn () => app(\App\Services\SubscriptionOpener::class)
            ->open($user, $paket, $bas, $bit, isset($veri['price']) ? (string) $veri['price'] : null, $veri['note'] ?? null));

        return redirect()->route('admin.subscriptions.index', $user)->with('success', 'Paket atandı.');
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

        $subscription->payments()->create($veri + ['recorded_by' => auth()->id()]);
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
