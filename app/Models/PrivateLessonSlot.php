<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dalga 25: haftalik ozel ders saati (yerel saat, ISO hafta gunu). */
class PrivateLessonSlot extends Model
{
    protected $fillable = ['student_id', 'weekday', 'starts_at', 'ends_at', 'starts_on', 'ends_on', 'created_by'];

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

    public function exceptions(): HasMany
    {
        return $this->hasMany(PrivateLessonException::class);
    }

    public function label(): string
    {
        return self::GUNLER[$this->weekday] . " {$this->starts_at}–{$this->ends_at}";
    }
}
