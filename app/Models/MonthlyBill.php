<?php

namespace App\Models;

use App\Enums\BillPaymentStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        // Tahsilat (DEV-507): bill_payments'tan turetilir, elle yazilmaz.
        'payment_status',
        'paid_amount',
        'paid_at',
        'payment_method',
        'paid_by',
        'payment_note',
    ];

    protected $casts = [
        'bill_month' => 'date',
        'total_amount' => 'decimal:2',
        'package_amount' => 'decimal:2',
        'generated_at' => 'datetime',
        'payment_status' => BillPaymentStatus::class,
        'paid_amount' => 'decimal:2',
        'paid_at' => 'date',
        'payment_method' => PaymentMethod::class,
    ];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = [
        'payment_status' => 'unpaid',
        'paid_amount' => 0,
    ];

    /**
     * Get the user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Alinan odemeler, geri alinanlar dahil (DEV-507). */
    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->orderBy('id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * Tahsil edilecek tutar: ADISYON (total_amount). Paket bedelinin
     * odemeleri abonelikte tutuluyor (Odemeler); burada ikinci kez
     * sayilmaz.
     */
    public function dueAmount(): float
    {
        return round((float) $this->total_amount, 2);
    }

    /** Acik kalan tutar; fazla odeme negatif gostermez. */
    public function remaining(): float
    {
        return max(0, round($this->dueAmount() - (float) $this->paid_amount, 2));
    }

    /**
     * Ozet sutunlari gecerli (geri alinmamis) odemelerden yeniden yazar:
     * odenen toplam, durum ve SON odemenin tarihi, yontemi, isaretleyeni,
     * notu. Odeme, geri alma ve adisyonun yeniden hesaplanmasi cagirir -
     * tutar artarsa "Odendi" adisyon "Kismen" olur.
     */
    public function syncPayment(): void
    {
        $gecerli = $this->payments()->whereNull('reversed_at')->get();
        $son = $gecerli->last();
        $odenen = round((float) $gecerli->sum('amount'), 2);

        $this->forceFill([
            'paid_amount' => $odenen,
            'payment_status' => BillPaymentStatus::for($this->dueAmount(), $odenen),
            'paid_at' => $son?->paid_on,
            'payment_method' => $son?->method,
            'paid_by' => $son?->recorded_by,
            'payment_note' => $son?->note,
        ])->save();
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
            ->inLocalMonth($year, $month)
            ->where('is_undone', false)
            ->get();

        // now()->setMonth() ayin 31'inde tasiyordu (31 Eki'de "31 Eylul" =
        // 1 Ekim) ve fatura yanlis aya yaziliyordu.
        $billMonth = \Illuminate\Support\Carbon::create($year, $month, 1)->startOfDay();

        $fatura = self::updateOrCreate(
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

        // Tutar degistiyse tahsilat durumu da degisir (DEV-507).
        $fatura->syncPayment();

        return $fatura;
    }
}
