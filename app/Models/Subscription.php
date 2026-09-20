<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ogrencinin bir paket donemi.
 *
 * price acilis anindaki tutardir; Package::monthly_price sonradan
 * degisse de burasi degismez. Fatura buradan okur.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'package_id',
        'starts_on',
        'ends_on',
        'price',
        'payment_status',
        'note',
        'created_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'price' => 'decimal:2',
        'payment_status' => PaymentStatus::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Verilen kafe gununde yururlukte olan, iptal edilmemis abonelikler. */
    public function scopeActiveOn(Builder $query, ?string $date = null): Builder
    {
        $gun = $date ?? LocalDay::today();

        return $query->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->whereDate('starts_on', '<=', $gun)
            ->whereDate('ends_on', '>=', $gun);
    }

    /** Fatura ayinda baslayanlar (fiyat basladigi ayda yazilir). */
    public function scopeStartingIn(Builder $query, int $year, int $month): Builder
    {
        $bas = Carbon::create($year, $month, 1);

        return $query->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->whereBetween('starts_on', [$bas->toDateString(), $bas->copy()->endOfMonth()->toDateString()]);
    }

    public function paidTotal(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function balance(): float
    {
        return max(0, round((float) $this->price - $this->paidTotal(), 2));
    }

    /** Vade: baslangic + config('kafe.odeme_vadesi_gun'). */
    public function dueOn(): Carbon
    {
        return $this->starts_on->copy()->addDays((int) config('kafe.odeme_vadesi_gun'));
    }

    public function isCancelled(): bool
    {
        return $this->payment_status === PaymentStatus::Cancelled;
    }

    /**
     * Odeme durumunu odemelerden ve vadeden turetir; degistiyse yazar.
     *
     * Iptal DOKUNULMAZ: iptal edilmis aboneligi odeme gelince "odendi"ye
     * cevirmek yanlis olur; once iptal geri alinmali (bu dalgada yok).
     */
    public function syncPaymentStatus(): PaymentStatus
    {
        if ($this->isCancelled()) {
            return PaymentStatus::Cancelled;
        }

        $yeni = match (true) {
            $this->balance() <= 0 => PaymentStatus::Paid,
            Carbon::parse(LocalDay::today())->greaterThan($this->dueOn()) => PaymentStatus::Overdue,
            default => PaymentStatus::Pending,
        };

        if ($this->payment_status !== $yeni) {
            $this->forceFill(['payment_status' => $yeni])->save();
        }

        return $yeni;
    }

    public function formattedPrice(): string
    {
        return number_format((float) $this->price, 2, ',', '.') . ' ₺';
    }

    public function formattedBalance(): string
    {
        return number_format($this->balance(), 2, ',', '.') . ' ₺';
    }
}
