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
    ];

    protected $casts = [
        'exam_date' => 'date',
        'exam_type' => ExamType::class,
    ];

    /** Bugun ve sonrasi, yakindan uzaga. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('exam_date', '>=', LocalDay::today())
            ->orderBy('exam_date')
            ->orderBy('starts_at');
    }

    /** Bugunden onceki denemeler, yeniden eskiye. */
    public function scopePast(Builder $query): Builder
    {
        return $query->where('exam_date', '<', LocalDay::today())
            ->orderByDesc('exam_date');
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        $bas = Carbon::create($year, $month, 1);

        return $query->whereBetween('exam_date', [
            $bas->toDateString(),
            $bas->copy()->endOfMonth()->toDateString(),
        ])->orderBy('exam_date')->orderBy('starts_at');
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
