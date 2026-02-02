<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscrepancyLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'product_id',
        'expected_quantity',
        'actual_quantity',
        'difference',
        'record_type',
        'detected_at',
        'resolved',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resolved' => 'boolean',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            $log->difference = $log->actual_quantity - $log->expected_quantity;
        });
    }

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
     * Get the resolver.
     */
    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Resolve this discrepancy.
     */
    public function resolve(User $admin, string $notes = null): void
    {
        $this->update([
            'resolved' => true,
            'resolution_notes' => $notes,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Get discrepancy type (shortage or surplus).
     */
    public function getDiscrepancyTypeAttribute(): string
    {
        if ($this->difference < 0) {
            return 'shortage'; // Eksik
        } elseif ($this->difference > 0) {
            return 'surplus'; // Fazla
        }
        return 'none';
    }

    /**
     * Get Turkish discrepancy type name.
     */
    public function getDiscrepancyTypeNameAttribute(): string
    {
        return match ($this->discrepancy_type) {
            'shortage' => 'Eksik',
            'surplus' => 'Fazla',
            default => 'Normal',
        };
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
     * Scope for unresolved discrepancies.
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    /**
     * Scope for resolved discrepancies.
     */
    public function scopeResolved($query)
    {
        return $query->where('resolved', true);
    }
}
