<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'location_id',
        'expected_quantity',
        'min_quantity',
    ];

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
     * Check if stock is low.
     */
    public function isLowStock(): bool
    {
        return $this->expected_quantity <= $this->min_quantity;
    }
}
