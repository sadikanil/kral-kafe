<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dalga 25: haftalik ozel ders saati (yerel saat, ISO hafta gunu). */
class PrivateLessonSlot extends Model
{
    protected $fillable = ['student_id', 'teacher_id', 'branch', 'weekday', 'starts_at', 'ends_at', 'starts_on', 'ends_on', 'created_by'];

    protected $casts = [
        'weekday' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public const GUNLER = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** Dersi veren koc ya da yonetici (1 Ekim 2026); eski satirlarda bos. */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** Bugun ve sonrasinda suren saatler (bitmis olanlar gecmiste kalir). */
    public function scopeCurrent(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', \App\Support\LocalDay::today()));
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PrivateLessonException::class);
    }

    public function label(): string
    {
        return self::GUNLER[$this->weekday] . " {$this->starts_at}–{$this->ends_at}";
    }
}
