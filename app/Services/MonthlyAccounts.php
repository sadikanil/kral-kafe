<?php

namespace App\Services;

use App\Enums\BillPaymentStatus;
use App\Enums\PaymentStatus;
use App\Models\Consumption;
use App\Models\MonthlyBill;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aylik hesaplar (21 Kasim 2026): her uyenin her ayi icin paket ve adisyon
 * AYRI ayri - yazilan, odenen, kalan, durum.
 *
 * Kurallar faturayla ayni (Dalga 7/22): paket bedeli donemin BASLADIGI aya
 * yazilir; adisyon o ayin geri alinmamis siparisleridir (monthly_bills,
 * her sipariste kendiliginden guncellenir). Kaynaklar degismez; bu sinif
 * yalnizca yan yana koyar.
 */
final class MonthlyAccounts
{
    /**
     * @param  Carbon  $from  ilk ayin 1'i
     * @param  Carbon  $to  son ayin 1'i (dahil)
     * @return Collection<int, array{user: User, ay: Carbon, paket: ?array, adisyon: ?array, kalan: float}>
     */
    public function rows(Carbon $from, Carbon $to, ?int $userId = null): Collection
    {
        $sonGun = $to->copy()->endOfMonth()->toDateString();
        $this->ensureBills($from, $to, $userId);

        $paketler = Subscription::query()
            ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->whereDate('starts_on', '>=', $from->toDateString())
            ->whereDate('starts_on', '<=', $sonGun)
            ->when($userId, fn ($q) => $q->where('student_id', $userId))
            ->with(['student', 'package', 'payments'])
            ->orderBy('starts_on')
            ->get()
            // Durum turetilir (vade gecti mi); bkz. PaymentStatement.
            ->each->syncPaymentStatus()
            ->groupBy(fn (Subscription $s) => $s->student_id . '|' . $s->starts_on->format('Y-m'));

        $adisyonlar = MonthlyBill::query()
            ->whereDate('bill_month', '>=', $from->toDateString())
            ->whereDate('bill_month', '<=', $sonGun)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where(fn ($q) => $q->where('total_amount', '>', 0)->orWhere('paid_amount', '>', 0))
            ->with('user')
            ->get()
            ->keyBy(fn (MonthlyBill $b) => $b->user_id . '|' . $b->bill_month->format('Y-m'));

        return $paketler->keys()->merge($adisyonlar->keys())->unique()
            ->map(function (string $anahtar) use ($paketler, $adisyonlar) {
                $donemler = $paketler->get($anahtar);
                $bill = $adisyonlar->get($anahtar);
                $uye = $donemler?->first()->student ?? $bill?->user;

                if ($uye === null) {
                    return null;
                }

                $paket = $donemler ? $this->paket($donemler) : null;
                $adisyon = $bill ? $this->adisyon($bill) : null;

                return [
                    'user' => $uye,
                    'ay' => Carbon::createFromFormat('Y-m-d', explode('|', $anahtar)[1] . '-01')->startOfDay(),
                    'paket' => $paket,
                    'adisyon' => $adisyon,
                    'kalan' => round(($paket['kalan'] ?? 0) + ($adisyon['kalan'] ?? 0), 2),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Siparisi olup aylik kaydi olmayan ay (Eylul gibi, kendiliginden acilma
     * oncesi) icin kaydi uretir: odeme ancak kayda alinabilir.
     */
    public function ensureBills(Carbon $from, Carbon $to, ?int $userId = null): void
    {
        [$bas] = LocalDay::monthBounds($from->year, $from->month);
        [, $bit] = LocalDay::monthBounds($to->year, $to->month);
        $tz = config('kafe.timezone');

        $gereken = Consumption::query()
            ->where('is_undone', false)
            ->whereBetween('consumed_at', [$bas, $bit])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get(['user_id', 'consumed_at'])
            ->map(fn (Consumption $c) => $c->user_id . '|' . $c->consumed_at->copy()->timezone($tz)->format('Y-m'))
            ->unique();

        if ($gereken->isEmpty()) {
            return;
        }

        $mevcut = MonthlyBill::query()
            ->whereDate('bill_month', '>=', $from->toDateString())
            ->whereDate('bill_month', '<=', $to->copy()->endOfMonth()->toDateString())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get(['user_id', 'bill_month'])
            ->map(fn (MonthlyBill $b) => $b->user_id . '|' . $b->bill_month->format('Y-m'));

        foreach ($gereken->diff($mevcut) as $anahtar) {
            [$id, $ay] = explode('|', $anahtar);
            $uye = User::find((int) $id);

            if ($uye !== null) {
                [$yil, $no] = array_map('intval', explode('-', $ay));
                MonthlyBill::generateForUserMonth($uye, $yil, $no);
            }
        }
    }

    /** @param Collection<int,Subscription> $donemler */
    private function paket(Collection $donemler): array
    {
        $tutar = round((float) $donemler->sum('price'), 2);
        $odenen = round((float) $donemler->sum(fn (Subscription $s) => $s->paidTotal()), 2);
        $kalan = round((float) $donemler->sum(fn (Subscription $s) => $s->balance()), 2);
        $acik = $donemler->filter(fn (Subscription $s) => $s->balance() > 0);

        [$etiket, $renk] = match (true) {
            $kalan <= 0 => ['Ödendi', 'success'],
            $acik->contains(fn (Subscription $s) => $s->payment_status === PaymentStatus::Overdue) => ['Gecikmiş', 'danger'],
            $odenen > 0 => ['Kısmen ödendi', 'info'],
            default => ['Bekliyor', 'warning'],
        };

        return [
            'donemler' => $donemler,
            'tutar' => $tutar,
            'odenen' => $odenen,
            'kalan' => $kalan,
            'etiket' => $etiket,
            'renk' => $renk,
            // Tek acik donem varsa odeme buradan alinir; birden fazlaysa paket sayfasindan.
            'odeme_donemi' => $acik->count() === 1 ? $acik->first() : null,
            'acik_donem_sayisi' => $acik->count(),
        ];
    }

    private function adisyon(MonthlyBill $bill): array
    {
        $tutar = $bill->dueAmount();
        $kalan = $bill->remaining();

        return [
            'bill' => $bill,
            'tutar' => $tutar,
            'odenen' => round((float) $bill->paid_amount, 2),
            'kalan' => $kalan,
            'etiket' => $tutar <= 0 ? 'Ödenecek tutar yok' : $bill->payment_status->label(),
            'renk' => $tutar <= 0 ? 'info' : $bill->payment_status->badgeClass(),
            'odendi' => $tutar > 0 && $bill->payment_status === BillPaymentStatus::Paid,
        ];
    }
}
