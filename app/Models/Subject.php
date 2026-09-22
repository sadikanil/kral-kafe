<?php

namespace App\Models;

use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ders/alan tanimi. VERIDEN gelir, koda gomulmez (Dalga 12).
 */
class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'exam_type', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'bool'];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForExamType(Builder $query, string $tur): Builder
    {
        return $query->where('exam_type', $tur);
    }
}
