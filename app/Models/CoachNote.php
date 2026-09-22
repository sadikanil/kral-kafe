<?php

namespace App\Models;

use App\Enums\CoachNoteKind;
use App\Enums\NoteVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koc notu ya da koc-veli gorusme kaydi (Dalga 14b).
 */
class CoachNote extends Model
{
    protected $fillable = [
        'student_id',
        'created_by',
        'kind',
        'visibility',
        'body',
        'occurred_on',
    ];

    protected $casts = [
        'kind' => CoachNoteKind::class,
        'visibility' => NoteVisibility::class,
        'occurred_on' => 'date',
    ];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = [
        'kind' => 'note',
        'visibility' => 'parent',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Veli ve ogrencinin gordugu notlar.
     *
     * TEK scope, iki ekran: SS6.1-3 veliye gidenin ogrenciye de gorunmesini
     * sart kosuyor. Ayri ayri yazilsaydi biri gun gelip digerinden fazlasini
     * gosterirdi - koc notunun sizmasi bu ozelligi olduren sey.
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', NoteVisibility::Parent->value);
    }

    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('student_id', $student->id);
    }

    /**
     * Ekranda gorunen tarih: gorusme kaydinda GORUSME gunu, duz notta
     * yazildigi gun.
     */
    public function displayDate(): \Illuminate\Support\Carbon
    {
        return $this->occurred_on ?? $this->created_at;
    }
}
