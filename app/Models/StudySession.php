<?php

namespace App\Models;

use App\Enums\SessionEndReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bir ogrencinin bir masadaki calisma oturumu.
 *
 * Sureler UTC saklanir (config/app.php timezone UTC ve oyle KALIR); gun/hafta
 * sinirlari App\Support\LocalDay uzerinden kafe saatine cevrilir.
 */
class StudySession extends Model
{
    protected $fillable = [
        'student_id',
        'study_table_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'end_reason' => SessionEndReason::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(StudyTable::class, 'study_table_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Istatistige giren oturumlar.
     *
     * Cok kisa oturum "yanlis okutma" sayilir: kayit SILINMEZ (veriyi yok etmek
     * denetimi imkansiz kilar) ama toplamlara katilmaz.
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at')
            ->where('duration_minutes', '>=', config('kafe.sayilabilir_dakika'));
    }

    /**
     * Acik oturumun su ana kadarki suresi; kapali oturumda kayitli sure.
     */
    public function minutesSoFar(?Carbon $now = null): int
    {
        $bitis = $this->ended_at ?? ($now ?? now());

        return max(0, (int) $this->started_at->diffInMinutes($bitis));
    }
}
