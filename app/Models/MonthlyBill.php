<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyBill extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bill_month',
        'total_items',
        'total_amount',
        'package_amount',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'bill_month' => 'date',
        'total_amount' => 'decimal:2',
        'package_amount' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get formatted total amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->total_amount, 2, ',', '.') . ' ₺';
    }

    /** Paket + tuketim. */
    public function grandTotal(): float
    {
        return round((float) $this->total_amount + (float) $this->package_amount, 2);
    }

    public function getFormattedPackageAttribute(): string
    {
        return number_format((float) $this->package_amount, 2, ',', '.') . ' ₺';
    }

    /** Raporlar sayfasi bu adla okuyordu ama accessor yoktu; genel toplam. */
    public function getFormattedTotalAttribute(): string
    {
        return number_format($this->grandTotal(), 2, ',', '.') . ' ₺';
    }

    /** Raporlar sayfasi bu adla okuyordu ama accessor yoktu. */
    public function getPeriodNameAttribute(): string
    {
        return $this->month_name;
    }

    /**
     * Get month name in Turkish.
     */
    public function getMonthNameAttribute(): string
    {
        $months = [
            1 => 'Ocak',
            2 => 'Şubat',
            3 => 'Mart',
            4 => 'Nisan',
            5 => 'Mayıs',
            6 => 'Haziran',
            7 => 'Temmuz',
            8 => 'Ağustos',
            9 => 'Eylül',
            10 => 'Ekim',
            11 => 'Kasım',
            12 => 'Aralık'
        ];

        return $months[$this->bill_month->month] . ' ' . $this->bill_month->year;
    }

    /**
     * Get Turkish status name.
     */
    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Beklemede',
            'sent' => 'Gönderildi',
            'exported' => 'Dışa Aktarıldı',
            default => $this->status,
        };
    }

    /**
     * Generate or update bill for a user and month.
     */
    public static function generateForUserMonth(User $user, int $year, int $month): self
    {
        $consumptions = Consumption::where('user_id', $user->id)
            ->whereYear('consumed_at', $year)
            ->whereMonth('consumed_at', $month)
            ->where('is_undone', false)
            ->get();

        $billMonth = now()->setYear($year)->setMonth($month)->startOfMonth();

        return self::updateOrCreate(
            [
                'user_id' => $user->id,
                'bill_month' => $billMonth,
            ],
            [
                'total_items' => $consumptions->sum('quantity'),
                'total_amount' => $consumptions->sum('total_price'),
                // Paket tutari HER ZAMAN subscriptions.price'tan; katalog
                // fiyati degisince gecmis fatura degismesin (Dalga 7).
                'package_amount' => Subscription::where('student_id', $user->id)
                    ->startingIn($year, $month)
                    ->sum('price'),
                'generated_at' => now(),
            ]
        );
    }
}
