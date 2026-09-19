<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StockPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'batch_id',
        'record_type',
        'photo_path',
        'ai_analysis',
        'processed_at',
        'uploaded_at',
        'admin_id',
    ];

    protected $casts = [
        'ai_analysis' => 'array',
        'processed_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($photo) {
            if (empty($photo->batch_id)) {
                $photo->batch_id = (string) Str::uuid();
            }
            if (empty($photo->uploaded_at)) {
                $photo->uploaded_at = now();
            }
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
     * Get the admin who uploaded.
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Check if photo has been processed by AI.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Get detected products from AI analysis.
     */
    public function getDetectedProducts(): array
    {
        return $this->ai_analysis['products_detected'] ?? [];
    }

    /**
     * Get anomalies from AI analysis.
     */
    public function getAnomalies(): array
    {
        return $this->ai_analysis['anomalies'] ?? [];
    }

    /**
     * Get overall AI confidence.
     */
    public function getAiConfidence(): ?float
    {
        return $this->ai_analysis['overall_confidence'] ?? null;
    }

    /**
     * Get photo URL.
     */
    public function getPhotoUrlAttribute(): string
    {
        return Storage::disk(config('filesystems.uploads'))->url($this->photo_path);
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

}
