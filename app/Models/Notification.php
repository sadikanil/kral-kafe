<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uretilmis bir bildirim.
 *
 * user_id KIME gidiyor, student_id KIMIN hakkinda. Veli bildiriminde ikisi
 * farkli; ogrenciye giden deneme hatirlatmasinda ayni kisi.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'user_id',
        'student_id',
        'related_id',
        'unique_key',
        'title',
        'body',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'type' => NotificationType::class,
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
