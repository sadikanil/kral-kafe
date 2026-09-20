<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'qr_code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Self adisyon icin sanal lokasyonun QR kodu. Hicbir duvarda basili degil.
     */
    public const SELF_SERVICE_QR = 'SELF-ADISYON';

    /**
     * Panelden eklenen tuketimlerin baglandigi sanal lokasyon.
     *
     * consumptions.location_id NOT NULL ve butun raporlar/ekranlar
     * location->name okuyor; sutunu nullable yapmak yerine tek bir sistem
     * lokasyonu kullaniliyor. is_active=false BILEREK: stok sayimi, QR
     * yazdirma ve panel sayaclari yalnizca acik lokasyonlari aldigi icin bu
     * satir oralara hic girmez. Self adisyon stok dusmez (ProductLocation
     * yok), yalnizca hesaba yazar.
     */
    public static function selfService(): self
    {
        return static::firstOrCreate(
            ['qr_code' => self::SELF_SERVICE_QR],
            [
                'name' => 'Self Adisyon',
                'type' => 'shelf',
                'description' => 'Sistem lokasyonu: öğrencinin panelden kendi eklediği tüketimler. Kapalı kalmalı; QR ile okutulmaz.',
                'is_active' => false,
            ]
        );
    }

    public function isSelfService(): bool
    {
        return $this->qr_code === self::SELF_SERVICE_QR;
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate QR code on creation
        static::creating(function ($location) {
            if (empty($location->qr_code)) {
                $location->qr_code = 'LOC-' . Str::upper(Str::random(8));
            }
        });
    }

    /**
     * Get the QR code URL for this location.
     */
    public function getQrUrlAttribute(): string
    {
        return url("/tuketim/{$this->qr_code}");
    }

    /**
     * Get products at this location.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_locations')
            ->withPivot(['expected_quantity', 'min_quantity'])
            ->withTimestamps();
    }

    /**
     * Get product locations.
     */
    public function productLocations()
    {
        return $this->hasMany(ProductLocation::class);
    }

    /**
     * Get stock records for this location.
     */
    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class);
    }

    /**
     * Get stock photos for this location.
     */
    public function stockPhotos()
    {
        return $this->hasMany(StockPhoto::class);
    }

    /**
     * Get consumptions at this location.
     */
    public function consumptions()
    {
        return $this->hasMany(Consumption::class);
    }

    /**
     * Scope for active locations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get Turkish type name.
     */
    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            'shelf' => 'Raf',
            'cabinet' => 'Dolap',
            'fridge' => 'Buzdolabı',
            default => $this->type,
        };
    }
}
