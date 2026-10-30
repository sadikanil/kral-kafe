<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adisyona alinan tek odeme (DEV-507, 27 Ekim 2026). Silinmez: geri alma
 * reversed_at/by yazar, kayit gecmiste kalir (kim, ne zaman, ne kadar).
 */
class BillPayment extends Model
{
    protected $fillable = ['monthly_bill_id', 'amount', 'method', 'paid_on', 'note', 'recorded_by', 'reversed_at', 'reversed_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'method' => PaymentMethod::class,
        'paid_on' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(MonthlyBill::class, 'monthly_bill_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
