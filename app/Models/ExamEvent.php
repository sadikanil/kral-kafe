<?php

namespace App\Models;

use App\Enums\ExamType;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Takvimdeki bir deneme sinavi (kafe geneli).
 *
 * "Kac gun kaldi" hesabi KAFE gunune gore: sunucu UTC'de gece 00:30 iken
 * Istanbul'da 03:30'dur ve deneme gunu gelmistir; UTC gunune bakan bir
 * hesap "yarin" derdi.
 */
class ExamEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'exam_type',
        'exam_date',
        'starts_at',
        'note',
        'created_by',
        'is_flexible',
        'available_until',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'available_until' => 'date',
        'is_flexible' => 'boolean',
        'exam_type' => ExamType::class,
    ];

    /**
     * Bugun ve sonrasi, yakindan uzaga - YALNIZCA DENEMELER.
     *
     * Resmi sinav (YKS/LGS) disarida: "siradaki deneme" hatirlaticisi onu
     * gosterseydi kutu "Sıradaki deneme: YKS" derdi. Resmi sinavin kendi
     * geri sayimi var (scopeNextOfficial), takvimde ise ikisi de duruyor.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('exam_date', '>=', LocalDay::today())
            ->where('exam_type', '!=', ExamType::Official->value)
            ->where('is_flexible', false)
            ->orderBy('exam_date')
            ->orderBy('starts_at');
    }

    /**
     * Serbest denemeler (Dalga 30a): penceresi kapanmamis olanlar. Tarihi
     * ogrenci secer; takvim gunune ve geri sayima girmezler.
     */
    public function scopeFlexibleOpen(Builder $query): Builder
    {
        return $query->where('is_flexible', true)
            ->where('available_until', '>=', LocalDay::today())
            ->orderBy('exam_date')
            ->orderBy('exam_type');
    }

    /** "1–30 Kasım" ya da ay atlarsa "15 Kasım – 10 Aralık". */
    public function windowLabel(): string
    {
        $bas = $this->exam_date->copy()->locale('tr');
        $son = ($this->available_until ?? $this->exam_date)->copy()->locale('tr');

        return $bas->month === $son->month
            ? $bas->day . '–' . $son->translatedFormat('j F')
            : $bas->translatedFormat('j F') . ' – ' . $son->translatedFormat('j F');
    }

    /** Siradaki resmi sinav (YKS/LGS). Yoksa null. */
    public function scopeUpcomingOfficial(Builder $query): Builder
    {
        return $query->where('exam_date', '>=', LocalDay::today())
            ->where('exam_type', ExamType::Official->value)
            ->orderBy('exam_date');
    }

    /** Bugunden onceki denemeler, yeniden eskiye. */
    public function scopePast(Builder $query): Builder
    {
        return $query->where('exam_date', '<', LocalDay::today())
            ->orderByDesc('exam_date');
    }

    /**
     * Yari acik aralik [ayin 1'i, sonraki ayin 1'i): SQLite'ta date cast
     * "2026-08-31 00:00:00" yaziyor ve kapali whereBetween metin
     * karsilastirmasinda ayin son gununu disarida birakiyordu. whereDate
     * degil: SQLite'ta sutunu strftime'a sarip indeksi kullanilmaz kilar.
     */
    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        $bas = Carbon::create($year, $month, 1);

        return $query->where('exam_date', '>=', $bas->toDateString())
            ->where('exam_date', '<', $bas->copy()->addMonthNoOverflow()->toDateString())
            ->where('is_flexible', false)->orderBy('exam_date')->orderBy('starts_at');
    }

    /** Y-m-d; takvim hucresi eslestirmesi icin. */
    public function dateKey(): string
    {
        return $this->exam_date->toDateString();
    }

    /**
     * Kafe gununden denemeye kalan gun. 0 bugun, negatif gecmis.
     */
    public function daysUntil(): int
    {
        return (int) Carbon::parse(LocalDay::today())->diffInDays($this->exam_date->toDateString(), false);
    }

    public function countdownLabel(): string
    {
        $gun = $this->daysUntil();

        return match (true) {
            $gun < 0 => 'Geçti',
            $gun === 0 => 'Bugün',
            $gun === 1 => 'Yarın',
            default => "{$gun} gün kaldı",
        };
    }

    /** "27 Eylül Cumartesi" - Turkce ay ve gun adi. */
    public function dateLabel(): string
    {
        return $this->exam_date->copy()->locale('tr')->translatedFormat('d F l');
    }
}
