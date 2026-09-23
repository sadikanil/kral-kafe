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
        'covered_quantity',
        'unit_price',
        'total_price',
        'consumed_at',
        'is_undone',
        'undone_at',
    ];

    protected $casts = [
        'covered_quantity' => 'integer',
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

        static::creating(function (Consumption $consumption) {
            if (empty($consumption->consumed_at)) {
                $consumption->consumed_at = now();
            }

            // Fiyat KAPSAMDAN SONRA hesaplanir. Bu satir Dalga 8'e kadar
            // kosulsuz unit_price * quantity yaziyordu; paket kapsami
            // girince dogrudan yanlis fatura uretirdi - SS4.2'de bu dalganin
            // onkosulu olarak isaretliydi.
            $consumption->total_price = $consumption->chargeableQuantity() * (float) $consumption->unit_price;
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
     * Ucreti odenecek adet: kapsanmayan kisim.
     */
    public function chargeableQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->covered_quantity);
    }

    /**
     * Tamami paket kapsaminda mi?
     *
     * Sutunda TUTULMUYOR, turetiliyor: covered_quantity ile yan yana duran
     * bir bool ikinci bir dogruluk kaynagi olurdu ve ikisi gunun birinde
     * ayrisirdi - ayni gerekce exam_result_subjects.net ve
     * study_tables.status icin de verilmisti.
     */
    public function isCoveredByPackage(): bool
    {
        return $this->quantity > 0 && $this->covered_quantity >= $this->quantity;
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
        return $this->consumed_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i');
    }

    /**
     * Scope for active (not undone) consumptions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_undone', false);
    }

    /**
     * KAFE saatine gore ay (README SS9.1.3). consumed_at UTC; whereMonth UTC
     * ayina baktigi icin yerel ayin ilk 3 saati onceki aya dusuyordu.
     * whereBetween indeksi de kullanir.
     */
    public function scopeInLocalMonth($query, int $year, int $month)
    {
        return $query->whereBetween('consumed_at', \App\Support\LocalDay::monthBounds($year, $month));
    }

    /** KAFE saatine gore gun (Y-m-d). */
    public function scopeOnLocalDay($query, string $day)
    {
        return $query->whereBetween('consumed_at', \App\Support\LocalDay::bounds($day));
    }

    /** Kafe saatine gore bu ay. */
    public function scopeCurrentMonth($query)
    {
        return $query->inLocalMonth(...\App\Support\LocalDay::yearMonth());
    }
}
