<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dalga 30b: bir dersin mufredat konusu (YKS konu listesi). */
class SubjectTopic extends Model
{
    protected $fillable = ['subject_id', 'name', 'sort_order'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
