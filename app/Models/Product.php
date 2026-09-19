<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'emoji',
        'category',
        'unit_price',
        'unit_type',
        'image_url',
        'barcode',
        'is_active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get locations where this product is stored.
     */
    public function locations()
    {
        return $this->belongsToMany(Location::class, 'product_locations')
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
     * Get consumptions of this product.
     */
    public function consumptions()
    {
        return $this->hasMany(Consumption::class);
    }

    /**
     * Get stock records for this product.
     */
    public function stockRecords()
    {
        return $this->hasMany(StockRecord::class);
    }

    /**
     * Scope for active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format((float) $this->unit_price, 2, ',', '.') . ' ₺';
    }

    /**
     * Get Turkish unit type name.
     */
    public function getUnitTypeNameAttribute(): string
    {
        // Try to map common english types if they still exist
        return match ($this->unit_type) {
            'piece' => 'Adet',
            'kg' => 'Kilogram',
            'liter' => 'Litre',
            'package' => 'Paket',
            'tin_can' => 'Teneke Kutu',
            'glass_bottle' => 'Cam Şişe',
            'pet_bottle' => 'Pet Şişe',
            default => $this->unit_type,
        };
    }

    /**
     * Yuklenen gorselin adresi.
     *
     * Adres yapilandirilmis yukleme diskinden uretilir; sabit "/storage/..."
     * yolu yazmak nesne depolamaya (Supabase Storage) gecince kirilir.
     */
    public function getImageSrcAttribute(): ?string
    {
        if (! $this->image_url) {
            return null;
        }

        return Storage::disk(config('filesystems.uploads'))->url($this->image_url);
    }
}
