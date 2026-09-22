<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gelistirilmesi gereken konu (Dalga 17b).
 */
class WeakTopic extends Model
{
    protected $fillable = [
        'student_id',
        'subject_id',
        'topic',
        'source',
        'status',
        'closed_at',
        'created_by',
    ];

    protected $casts = ['closed_at' => 'datetime'];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = [
        'status' => 'open',
        'source' => 'coach',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('student_id', $student->id);
    }

    /**
     * Konuyu kapatir - ama YALNIZCA hala aciksa.
     *
     * Kosul WHERE'de: ikinci kez kapatmak kapanma anini ileri
     * kaydirmamali. "Ne zaman hallettik" sorusu, sayfayi iki kez
     * yenilemekle degismemeli - StudyPlanItem::markDone ile ayni gerekce.
     */
    public function close(): bool
    {
        $etkilenen = static::whereKey($this->getKey())
            ->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);

        $this->refresh();

        return $etkilenen === 1;
    }

    /** Dususun tekrarlamasi olagan; kapanan konu yeniden acilabilir. */
    public function reopen(): void
    {
        $this->update(['status' => 'open', 'closed_at' => null]);
    }
}
