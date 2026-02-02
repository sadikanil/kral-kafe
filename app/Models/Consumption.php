<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Consumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'location_id',
        'quantity',
        'unit_price',
        'total_price',
        'consumed_at',
        'is_undone',
        'undone_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'consumed_at' => 'datetime',
        'undone_at' => 'datetime',
        'is_undone' => 'boolean',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-calculate total price
        static::creating(function ($consumption) {
            if (empty($consumption->consumed_at)) {
                $consumption->consumed_at = now();
            }
            $consumption->total_price = $consumption->unit_price * $consumption->quantity;
        });
    }

    /**
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the location.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Check if this consumption can be undone (within 60 seconds).
     */
    public function canUndo(): bool
    {
        if ($this->is_undone) {
            return false;
        }
        return $this->consumed_at->diffInSeconds(now()) <= 60;
    }

    /**
     * Undo this consumption.
     */
    public function undo(): bool
    {
        if (!$this->canUndo()) {
            return false;
        }

        $this->update([
            'is_undone' => true,
            'undone_at' => now(),
        ]);

        return true;
    }

    /**
     * Get formatted total price.
     */
    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->total_price, 2, ',', '.') . ' ₺';
    }

    /**
     * Get formatted consumed date.
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->consumed_at->format('d.m.Y H:i');
    }

    /**
     * Scope for active (not undone) consumptions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_undone', false);
    }

    /**
     * Scope for current month.
     */
    public function scopeCurrentMonth($query)
    {
        return $query->whereMonth('consumed_at', now()->month)
            ->whereYear('consumed_at', now()->year);
    }
}
