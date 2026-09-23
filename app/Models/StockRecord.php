<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'product_id',
        'record_type',
        'verified_quantity',
        'ai_suggested_quantity',
        'ai_confidence',
        'admin_id',
        'recorded_at',
        'notes',
    ];

    protected $casts = [
        'ai_confidence' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    /**
     * Get the location.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the admin who verified.
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Check if AI suggestion differs from verified.
     */
    public function hasDiscrepancy(): bool
    {
        if ($this->ai_suggested_quantity === null) {
            return false;
        }
        return $this->verified_quantity !== $this->ai_suggested_quantity;
    }

    /**
     * Get discrepancy amount.
     */
    public function getDiscrepancyAmount(): int
    {
        if ($this->ai_suggested_quantity === null) {
            return 0;
        }
        return $this->verified_quantity - $this->ai_suggested_quantity;
    }

    /**
     * Get Turkish record type name.
     */
    public function getRecordTypeNameAttribute(): string
    {
        return match ($this->record_type) {
            'opening' => 'Açılış',
            'closing' => 'Kapanış',
            default => $this->record_type,
        };
    }

    /**
     * Get formatted confidence.
     */
    public function getFormattedConfidenceAttribute(): string
    {
        if ($this->ai_confidence === null) {
            return '-';
        }
        return '%' . round($this->ai_confidence * 100);
    }

    /**
     * Scope for opening records.
     */
    public function scopeOpening($query)
    {
        return $query->where('record_type', 'opening');
    }

    /**
     * Scope for closing records.
     */
    public function scopeClosing($query)
    {
        return $query->where('record_type', 'closing');
    }

    /**
     * Scope for today.
     */
    public function scopeToday($query)
    {
        // Kafe saatine gore bugun; whereDate UTC gunune bakardi.
        return $query->whereBetween('recorded_at', \App\Support\LocalDay::bounds(\App\Support\LocalDay::today()));
    }
}
