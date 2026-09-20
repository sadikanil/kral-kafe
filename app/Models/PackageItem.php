<?php

namespace App\Models;

use App\Enums\PackagePeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pakete dahil urun ve limiti. included_quantity null = sinirsiz.
 */
class PackageItem extends Model
{
    protected $fillable = [
        'package_id',
        'product_id',
        'included_quantity',
        'period',
    ];

    protected $casts = [
        'included_quantity' => 'integer',
        'period' => PackagePeriod::class,
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isUnlimited(): bool
    {
        return $this->included_quantity === null;
    }

    public function label(): string
    {
        return $this->isUnlimited()
            ? 'sınırsız'
            : $this->period->label() . ' ' . $this->included_quantity . ' adet';
    }
}
