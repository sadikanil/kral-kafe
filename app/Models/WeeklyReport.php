<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir ogrencinin bir haftasinin dondurulmus raporu (Dalga 15a).
 */
class WeeklyReport extends Model
{
    protected $fillable = [
        'student_id',
        'week_start',
        'payload',
        'coach_comment',
        'generated_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'payload' => 'array',
        'generated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** Gecen haftaya gore dakika farki. Eksi: dusus. */
    public function minutesChange(): int
    {
        return (int) ($this->payload['minutes'] ?? 0) - (int) ($this->payload['previous_minutes'] ?? 0);
    }

    /**
     * Hedef tutturuldu mu?
     *
     * Hedefi OLMAYAN ogrenci "tutturamadi" sayilmaz - hedefsizlik bir
     * basarisizlik degil. Ekranda hedef cubugu da hic cizilmiyor.
     */
    public function goalMet(): bool
    {
        $hedef = $this->payload['goal_minutes'] ?? null;

        return $hedef !== null && (int) ($this->payload['minutes'] ?? 0) >= (int) $hedef;
    }

    /**
     * Hedefin yuzde kaci. Hedef yoksa null.
     */
    public function goalPercent(): ?int
    {
        $hedef = (int) ($this->payload['goal_minutes'] ?? 0);

        if ($hedef < 1) {
            return null;
        }

        return (int) min(100, round(((int) ($this->payload['minutes'] ?? 0)) / $hedef * 100));
    }
}
