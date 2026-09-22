<?php

namespace App\Models;

use App\Enums\PlanPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Haftalik calisma plani maddesi (Dalga 13).
 */
class StudyPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'subject_id',
        'title',
        'period',
        'week_start',
        'status',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'period' => PlanPeriod::class,
        'week_start' => 'date',
        'completed_at' => 'datetime',
    ];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = [
        'status' => 'open',
        'period' => PlanPeriod::Week->value,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Bir ogrencinin bir donemdeki maddeleri.
     *
     * DONEM SUZGECI SART. week_start tek sutun: haftalik madde pazartesiyi,
     * aylik madde ayin 1'ini tutuyor. Ayin 1'i pazartesiye denk geldiginde
     * (or. 1 Haziran 2026) ikisi AYNI degeri tasir; suzgec olmazsa aylik
     * hedefler haftalik listeye sizar ve tamamlama orani bozulur.
     */
    public function scopeForPeriod(Builder $query, User $student, PlanPeriod $period, string $start): Builder
    {
        return $query->where('student_id', $student->id)
            ->where('period', $period->value)
            ->whereDate('week_start', $start);
    }

    public function scopeForWeek(Builder $query, User $student, string $week): Builder
    {
        return $query->forPeriod($student, PlanPeriod::Week, $week);
    }

    /**
     * Maddeyi tamamlar - ama YALNIZCA hala aciksa.
     *
     * Kosul WHERE'de: ikinci kez isaretlemek tamamlanma anini ileri
     * kaydirmamali. "Ne zaman bitirdi" sorusu, sayfayi iki kez yenilemekle
     * degismemeli - closeOnce ile ayni gerekce.
     */
    public function markDone(): bool
    {
        $etkilenen = static::whereKey($this->getKey())
            ->where('status', 'open')
            ->update([
                'status' => 'done',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $etkilenen === 1;
    }

    /**
     * Bir donemin ilerlemesi: [tamamlanan, toplam].
     *
     * @return array{0:int,1:int}
     */
    public static function progress(User $student, PlanPeriod $period, string $start): array
    {
        $maddeler = static::forPeriod($student, $period, $start)->get();

        return [
            $maddeler->where('status', 'done')->count(),
            $maddeler->count(),
        ];
    }

    /**
     * Haftalik ilerleme. progress()'e delege eder ki donem suzgeci tek
     * yerde kalsin - veli ve yonetici panelleri bunu cagiriyor.
     *
     * @return array{0:int,1:int}
     */
    public static function weeklyProgress(User $student, string $week): array
    {
        return static::progress($student, PlanPeriod::Week, $week);
    }
}
