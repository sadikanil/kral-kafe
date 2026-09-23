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
     * Tutarsizligi bir kez cozer; zaten cozulmusse hicbir sey yazmaz.
     *
     * Form "not daha sonra degistirilemez" diyor. Kosul PHP'de degil
     * UPDATE'in icinde: eski sekme, ikinci yonetici ya da cift tiklama
     * ayni anda gelse de yalnizca ilk yazan kazanir (StudySession::closeOnce
     * ile ayni yol). Donus: bu cagri mi cozdu.
     */
    public function resolve(User $admin, ?string $notes = null): bool
    {
        $etkilenen = static::whereKey($this->getKey())
            ->where('resolved', false)
            ->update([
                'resolved' => true,
                'resolution_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        // Kaybeden kopya da veritabanindaki notu ve cozeni gostersin.
        $this->refresh();

        return $etkilenen === 1;
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
