<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dalga 25: bir tarihteki ozel dersin iptali ya da tasinmasi. */
class PrivateLessonException extends Model
{
    protected $fillable = ['private_lesson_slot_id', 'date', 'cancelled', 'new_date', 'new_starts_at', 'new_ends_at'];

    protected $casts = [
        'date' => 'date',
        'new_date' => 'date',
        'cancelled' => 'boolean',
    ];
}
