<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uyelik paketi (katalog). Fiyati degistirmek mevcut abonelikleri
 * DEGISTIRMEZ - abonelik acilirken fiyat kopyalanir.
 */
class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'monthly_price',
        'has_reserved_table',
        'includes_coaching',
        'weekly_mock_exams',
        'description',
        'is_active',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'has_reserved_table' => 'boolean',
        'includes_coaching' => 'boolean',
        'weekly_mock_exams' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'has_reserved_table' => false,
        'includes_coaching' => false,
        'weekly_mock_exams' => 0,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function formattedPrice(): string
    {
        return number_format((float) $this->monthly_price, 2, ',', '.') . ' ₺';
    }
}
