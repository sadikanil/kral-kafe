<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Ogrencinin bir paket donemi.
 *
 * price acilis anindaki tutardir; Package::monthly_price sonradan
 * degisse de burasi degismez. Fatura buradan okur.
 *
 * Yenileme (18 Kasim 2026): auto_renew acik donem bitince ayni paketle ve
 * ayni tutarla bir sonraki donem acilir (SubscriptionRenewer). Zincir
 * renewed_from_id ile kurulur; her donemin odemesi yine kendine yazilir.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'package_id',
        'starts_on',
        'ends_on',
        'price',
        'payment_status',
        'note',
        'created_by',
        'auto_renew',
        'renewed_from_id',
        'next_package_id',
        'next_price',
        'base_price',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'price' => 'decimal:2',
        'payment_status' => PaymentStatus::class,
        'auto_renew' => 'boolean',
        'next_price' => 'decimal:2',
        'base_price' => 'decimal:2',
    ];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = [
        'auto_renew' => false,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Yenileme (18 Kas 2026): bu donemi acan onceki donem. */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** Bu donemin yenilenmesiyle acilan sonraki donem (en fazla bir). */
    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }

    /** "Sonraki donemden itibaren" secilen paket; yenileme bunu kullanir. */
    public function nextPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'next_package_id');
    }

    /** Yeni donemin baslayacagi gun: bitisin ertesi gunu. */
    public function renewsOn(): Carbon
    {
        return $this->ends_on->copy()->addDay();
    }

    /** Uyenin aylik tutari; eski satirlarda (base_price yok) donem tutari. */
    public function monthlyPrice(): string
    {
        return (string) ($this->base_price ?? $this->price);
    }

    /** Yenilemede yazilacak paket ve AYLIK tutar (sonraki donem degisikligi dahil). */
    public function nextTerms(): array
    {
        if ($this->next_package_id === null) {
            return [$this->package, $this->monthlyPrice()];
        }

        $paket = $this->nextPackage ?? $this->package;

        return [$paket, (string) ($this->next_price ?? $paket->monthly_price)];
    }

    /** Takvim ayi: baslangicin ayinin son gunu. */
    public static function monthEndFor(Carbon $bas): Carbon
    {
        return $bas->copy()->endOfMonth()->startOfDay();
    }

    /**
     * Donem tutari (21 Kas 2026): ayin tamami aylik tutar; ay icinde kalan
     * kisa donem gun orantisiyla (kist), tam liraya yuvarlanir. Birden cok
     * aya yayilan eski donemler orantilanmaz.
     */
    public static function priceFor(string|float $aylik, Carbon $bas, Carbon $bit): string
    {
        $aylik = (float) $aylik;

        if (! $bas->isSameMonth($bit) || $bas->day === 1 && $bit->isSameDay($bas->copy()->endOfMonth())) {
            return number_format($aylik, 2, '.', '');
        }

        $gun = (int) $bas->diffInDays($bit) + 1;

        return number_format(round($aylik * $gun / $bas->daysInMonth), 2, '.', '');
    }

    /** Ay ortasinda baslayan/biten (kist) donem mi. */
    public function isPartialMonth(): bool
    {
        return $this->starts_on->isSameMonth($this->ends_on)
            && ! ($this->starts_on->day === 1 && $this->ends_on->isSameDay($this->starts_on->copy()->endOfMonth()));
    }

    /**
     * Ayni "yuvada" daha yeni bir donem var mi: ana paket icin baska bir ana
     * paket, ek paket icin ayni ek paket. Varsa bu donem yenilenmez - zincir
     * yenisinden surer. Iptal edilmis donem sayilmaz.
     */
    public function isSuperseded(): bool
    {
        return self::where('student_id', $this->student_id)
            ->whereKeyNot($this->id)
            ->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            // Daha gec baslayan ya da ayni gun baslayip sonra yazilan: canlida
            // ayni gun baslayan iki ana paket var; ikisi birden yenilenmesin.
            // whereDate: SQLite 'date' cast'i "Y-m-d 00:00:00" yaziyor.
            ->where(fn ($q) => $q->whereDate('starts_on', '>', $this->starts_on->toDateString())
                ->orWhere(fn ($ayniGun) => $ayniGun->whereDate('starts_on', $this->starts_on->toDateString())->where('id', '>', $this->id)))
            ->when(
                $this->package->is_addon,
                fn ($q) => $q->where('package_id', $this->package_id),
                fn ($q) => $q->whereHas('package', fn ($p) => $p->where('is_addon', false)),
            )
            ->exists();
    }

    /**
     * Yonetim "Her ay yenile"yi bu donemde acabilir mi: iptal degil, henuz
     * yenilenmemis, bitmemis ve yerine daha yenisi gelmemis. Bitmis donemi
     * acmak gecmis aylari borc olarak yazardi; o durumda paket yeniden atanir.
     */
    public function canToggleRenewal(): bool
    {
        return ! $this->isCancelled()
            && $this->ends_on->toDateString() >= LocalDay::today()
            && ! $this->renewal()->exists()
            && ! $this->isSuperseded();
    }

    /** Verilen kafe gununde yururlukte olan, iptal edilmemis abonelikler. */
    public function scopeActiveOn(Builder $query, ?string $date = null): Builder
    {
        $gun = $date ?? LocalDay::today();

        return $query->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->whereDate('starts_on', '<=', $gun)
            ->whereDate('ends_on', '>=', $gun);
    }

    /**
     * Fatura ayinda baslayanlar (fiyat basladigi ayda yazilir).
     *
     * Yari acik aralik (>= ayin 1'i, < sonraki ayin 1'i): 'date' cast SQLite'a
     * "2026-09-30 00:00:00" yaziyor ve kapali whereBetween('...', '2026-09-30')
     * metin karsilastirmasinda ayin SON gununu disarida birakiyordu - o gun
     * baslayan paket hicbir ayin dokumune ve faturasina girmiyordu. whereDate
     * yerine bu bicim: sutuna fonksiyon uygulanmaz, indeks kullanilir.
     */
    public function scopeStartingIn(Builder $query, int $year, int $month): Builder
    {
        $bas = Carbon::create($year, $month, 1);

        return $query->where('payment_status', '!=', PaymentStatus::Cancelled->value)
            ->where('starts_on', '>=', $bas->toDateString())
            ->where('starts_on', '<', $bas->copy()->addMonthNoOverflow()->toDateString());
    }

    /**
     * On yuklenmis odemeler varsa onlardan: odeme takibi listesi senkron,
     * "Odenen" ve "Kalan" icin satir basina uc ayri sum() atiyordu (40
     * abonelikte 120 fazla sorgu). Iliski yuklu degilse taze sorgu - yeni
     * yazilan odeme kalani hemen dusurmeli.
     */
    public function paidTotal(): float
    {
        return (float) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount'));
    }

    public function balance(): float
    {
        return max(0, round((float) $this->price - $this->paidTotal(), 2));
    }

    /** Vade: baslangic + config('kafe.odeme_vadesi_gun'). */
    public function dueOn(): Carbon
    {
        return $this->starts_on->copy()->addDays((int) config('kafe.odeme_vadesi_gun'));
    }

    public function isCancelled(): bool
    {
        return $this->payment_status === PaymentStatus::Cancelled;
    }

    /**
     * Odeme durumunu odemelerden ve vadeden turetir; degistiyse yazar.
     *
     * Iptal DOKUNULMAZ: iptal edilmis aboneligi odeme gelince "odendi"ye
     * cevirmek yanlis olur; once iptal geri alinmali (bu dalgada yok).
     */
    public function syncPaymentStatus(): PaymentStatus
    {
        if ($this->isCancelled()) {
            return PaymentStatus::Cancelled;
        }

        $yeni = match (true) {
            $this->balance() <= 0 => PaymentStatus::Paid,
            Carbon::parse(LocalDay::today())->greaterThan($this->dueOn()) => PaymentStatus::Overdue,
            default => PaymentStatus::Pending,
        };

        if ($this->payment_status !== $yeni) {
            $this->forceFill(['payment_status' => $yeni])->save();
        }

        return $yeni;
    }

    public function formattedPrice(): string
    {
        return number_format((float) $this->price, 2, ',', '.') . ' ₺';
    }

    public function formattedBalance(): string
    {
        return number_format($this->balance(), 2, ',', '.') . ' ₺';
    }
}
