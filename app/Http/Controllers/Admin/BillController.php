<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillPaymentStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Models\MonthlyBill;
use App\Support\ExamCalendar;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Adisyonlar ve tahsilat (DEV-507, 27 Ekim 2026). Yalnizca yonetici
 * (rota grubu: admin).
 *
 * Yonetici aylik adisyona "Odeme alindi" der: tutar (kismi olabilir),
 * yontem, tarih, not. Her odeme bill_payments'a yazilir ve SILINMEZ;
 * geri alma kaydi isaretler (kim, ne zaman). Adisyonun ozet sutunlari
 * gecerli odemelerden turetilir (MonthlyBill::syncPayment).
 */
class BillController extends Controller
{
    /** Formdaki yontemler (kartta "Diger" yok). */
    private const YONTEMLER = [PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer];

    private const DURUMLAR = ['odenmemis', 'odendi', 'tumu'];

    public function index(Request $request): View
    {
        $durum = in_array($request->query('durum'), self::DURUMLAR, true) ? $request->query('durum') : 'odenmemis';
        $ay = $this->ay($request->query('ay'));

        $kapsam = MonthlyBill::query()->when($ay, fn (Builder $q) => $q->whereDate('bill_month', $ay->toDateString()));

        $adisyonlar = (clone $kapsam)
            ->when($durum === 'odenmemis', fn (Builder $q) => $this->acik($q))
            ->when($durum === 'odendi', fn (Builder $q) => $q->where('payment_status', BillPaymentStatus::Paid->value)->where('total_amount', '>', 0))
            ->with(['user', 'payments.recorder', 'payments.reverser'])
            ->orderByDesc('bill_month')->orderBy('user_id')
            ->paginate(50)->withQueryString();

        // Acik alacak: secili ayda (yoksa hepsi) adisyon - odenen.
        $acik = $this->acik(clone $kapsam)->selectRaw('COALESCE(SUM(total_amount), 0) - COALESCE(SUM(paid_amount), 0) AS acik')->value('acik');

        return view('admin.bills.index', [
            'bills' => $adisyonlar,
            'openTotal' => round((float) $acik, 2),
            'status' => $durum,
            'month' => $ay,
            'monthLabel' => $ay ? ExamCalendar::monthLabel($ay->year, $ay->month) : null,
            'months' => MonthlyBill::query()->select('bill_month')->distinct()->orderByDesc('bill_month')->limit(24)->pluck('bill_month'),
            'methods' => self::YONTEMLER,
            'today' => LocalDay::today(),
        ]);
    }

    public function storePayment(Request $request, MonthlyBill $bill): RedirectResponse
    {
        $veri = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'method' => ['required', Rule::in(array_column(self::YONTEMLER, 'value'))],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . LocalDay::today()],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'paid_on.before_or_equal' => 'Ödeme tarihi bugünden sonra olamaz.',
        ], [
            'amount' => 'tutar', 'method' => 'ödeme yöntemi', 'paid_on' => 'ödeme tarihi', 'note' => 'not',
        ]);

        // Kilit: ayni anda iki "Odeme alindi" kalan tutari asamasin.
        DB::transaction(function () use ($bill, $veri, $request) {
            $kilitli = MonthlyBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $kalan = $kilitli->remaining();

            if ($kalan <= 0) {
                throw ValidationException::withMessages(['amount' => 'Bu adisyonun açık tutarı yok.']);
            }
            if (round((float) $veri['amount'], 2) > $kalan) {
                throw ValidationException::withMessages([
                    'amount' => 'Tutar açık kalan tutarı (' . number_format($kalan, 2, ',', '.') . ' ₺) aşamaz.',
                ]);
            }

            $kilitli->payments()->create([
                'amount' => round((float) $veri['amount'], 2),
                'method' => $veri['method'],
                'paid_on' => $veri['paid_on'] ?? LocalDay::today(),
                'note' => $veri['note'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $kilitli->syncPayment();
        });

        $bill->refresh()->load('user');

        return back()->with('success', sprintf(
            '%s · %s: %s ödeme alındı. Durum: %s.',
            $bill->user?->name ?? 'Silinmiş kullanıcı',
            $bill->month_name,
            number_format((float) $veri['amount'], 2, ',', '.') . ' ₺',
            $bill->payment_status->label(),
        ));
    }

    /** Odemeyi geri alir; kayit silinmez, kim ve ne zaman geri aldigi yazilir. */
    public function reversePayment(Request $request, BillPayment $payment): RedirectResponse
    {
        $geriAlindi = DB::transaction(function () use ($payment, $request) {
            $kilitli = BillPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($kilitli->isReversed()) {
                return false;
            }

            $kilitli->update(['reversed_at' => now(), 'reversed_by' => $request->user()->id]);
            $kilitli->bill->syncPayment();

            return true;
        });

        if (! $geriAlindi) {
            return back()->with('error', 'Bu ödeme zaten geri alınmış.');
        }

        return back()->with('success', number_format((float) $payment->amount, 2, ',', '.') . ' ₺ ödeme geri alındı.');
    }

    /** Odenmemis ya da kismi; tahsil edilecek tutari olanlar. */
    private function acik(Builder $q): Builder
    {
        return $q->where('payment_status', '!=', BillPaymentStatus::Paid->value)->where('total_amount', '>', 0);
    }

    /** ?ay=YYYY-MM -> ayin ilk gunu; bos ya da bozuksa null (tum aylar). */
    private function ay(mixed $deger): ?Carbon
    {
        return is_string($deger) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $deger, $m)
            ? Carbon::create((int) $m[1], (int) $m[2], 1)
            : null;
    }
}
