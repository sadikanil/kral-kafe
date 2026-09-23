<?php

namespace App\Models;

use App\Enums\CommitmentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Haftalik sabit program satiri (Dalga 30c): "Okul · Pzt 08:00-15:00".
 * weekday: 1 = pazartesi ... 7 = pazar (ISO).
 */
class StudentCommitment extends Model
{
    protected $fillable = ['student_id', 'kind', 'title', 'weekday', 'starts_at', 'ends_at', 'created_by'];

    protected $casts = [
        'kind' => CommitmentKind::class,
        'weekday' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** "Okul" ya da "Dershane · Limit". */
    public function label(): string
    {
        return $this->kind->label() . ($this->title ? ' · ' . $this->title : '');
    }
}
