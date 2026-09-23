<?php

namespace App\Services;

use App\Models\Consumption;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ogrencinin bir aylik hesap dokumu (Dalga 22).
 *
 * Aylik faturayla AYNI kurallar: paket bedeli basladigi aya yazilir
 * (Subscription::startingIn), adisyonda yalnizca geri alinmamislar ve
 * ucretli kisim (total_price; pakete dahil adetler zaten dusulmus).
 */
final class PaymentStatement
{
    /**
     * @param Collection<int,Subscription> $subscriptions
     * @param Collection<int,Consumption> $consumptions
     */
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        private readonly Collection $subscriptions,
        private readonly Collection $consumptions,
    ) {}

    public static function for(User $student, int $year, int $month): self
    {
        return new self(
            $year,
            $month,
            Subscription::where('student_id', $student->id)
                ->startingIn($year, $month)
                ->with(['package', 'payments'])
                ->orderBy('starts_on')
                ->get()
                // Durum turetilir (Dalga 7): vadenin gecmesi bir yazma degil,
                // saklanan deger ancak bir ekran senkronlayinca guncellenir.
                // Senkronlamasak gecen ayin odenmemis paketi "Bekliyor" kalirdi.
                ->each->syncPaymentStatus(),
            Consumption::with('product')
                ->where('user_id', $student->id)
                ->where('is_undone', false)
                ->inLocalMonth($year, $month)
                ->orderBy('consumed_at')
                ->get(),
        );
    }

    /** @return Collection<int,Subscription> */
    public function subscriptions(): Collection
    {
        return $this->subscriptions;
    }

    /** @return Collection<int,Consumption> */
    public function consumptions(): Collection
    {
        return $this->consumptions;
    }

    public function packageTotal(): float
    {
        return round((float) $this->subscriptions->sum('price'), 2);
    }

    public function paidTotal(): float
    {
        return round((float) $this->subscriptions->flatMap->payments->sum('amount'), 2);
    }

    public function packageBalance(): float
    {
        return max(0, round($this->packageTotal() - $this->paidTotal(), 2));
    }

    public function spendingTotal(): float
    {
        return round((float) $this->consumptions->sum('total_price'), 2);
    }

    public function monthTotal(): float
    {
        return round($this->packageTotal() + $this->spendingTotal(), 2);
    }

    public static function money(float $tutar): string
    {
        return number_format($tutar, 2, ',', '.') . ' ₺';
    }
}
