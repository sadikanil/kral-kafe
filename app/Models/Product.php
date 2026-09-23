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
        'description',
        'location_id',
        'stock_quantity',
        'critical_quantity',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
        'stock_quantity' => 'integer',
        'critical_quantity' => 'integer',
    ];

    /**
     * Kritik stok bildirimi (Dalga 29): sinir GECILDIGI anda, bir kez.
     *
     * Her satista degil: "kritik mi" once hayir sonra evet olunca. Stok
     * yeniden ustune cikip tekrar inerse yeniden uyarir. Kritik sayinin
     * stogun ustune cekilmesi de bir gecistir.
     */
    protected static function booted(): void
    {
        static::created(function (Product $urun) {
            if ($urun->isCritical()) {
                app(\App\Services\NotificationBuilder::class)->lowStock($urun);
            }
        });

        static::updated(function (Product $urun) {
            if (! $urun->wasChanged(['stock_quantity', 'critical_quantity'])) {
                return;
            }

            $onceden = self::criticalFor($urun->getOriginal('stock_quantity'), $urun->getOriginal('critical_quantity'));

            if (! $onceden && $urun->isCritical()) {
                app(\App\Services\NotificationBuilder::class)->lowStock($urun);
            }
        });
    }

    /** Bos stok = takip kapali (sicak icecek, cay). */
    public function tracksStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    /** Kritik sayiya (dahil) indi mi? Kritik sayi yoksa yalnizca tukenince. */
    public function isCritical(): bool
    {
        return self::criticalFor($this->stock_quantity, $this->critical_quantity);
    }

    private static function criticalFor(?int $stok, ?int $kritik): bool
    {
        return $stok !== null && $kritik !== null && $stok <= $kritik;
    }

    /** untracked | out | critical | ok - stok sayfasinin rozeti ve filtresi. */
    public function stockStatus(): string
    {
        return match (true) {
            ! $this->tracksStock() => 'untracked',
            $this->stock_quantity <= 0 => 'out',
            $this->isCritical() => 'critical',
            default => 'ok',
        };
    }

    /**
     * Filtre: 'critical' tukenenleri de kapsar (ikisi de "siparis ver").
     */
    public function scopeWithStockStatus($query, string $durum)
    {
        return match ($durum) {
            'untracked' => $query->whereNull('stock_quantity'),
            'out' => $query->where('stock_quantity', '<=', 0),
            'critical' => $query->whereNotNull('stock_quantity')->where(fn ($q) => $q
                ->where('stock_quantity', '<=', 0)
                ->orWhereColumn('stock_quantity', '<=', 'critical_quantity')),
            'ok' => $query->where('stock_quantity', '>', 0)->where(fn ($q) => $q
                ->whereNull('critical_quantity')
                ->orWhereColumn('stock_quantity', '>', 'critical_quantity')),
            default => $query,
        };
    }

    /**
     * Satis (-) ya da iade (+). Takip kapaliysa hicbir sey yapmaz.
     *
     * Eloquent increment/decrement: tek UPDATE (atomik) ve 'updated' olayi
     * tetiklenir, yani kritik stok bildirimi burada da calisir.
     */
    public function adjustStock(int $fark): void
    {
        if (! $this->tracksStock() || $fark === 0) {
            return;
        }

        $fark > 0
            ? $this->increment('stock_quantity', $fark)
            : $this->decrement('stock_quantity', -$fark);
    }

    /**
     * Konum etiketi (Dalga 29): urunun durdugu tek yer. Bos olabilir.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
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
