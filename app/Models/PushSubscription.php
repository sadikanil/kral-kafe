<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telefon bildirimi aboneligi (6 Ekim 2026): bir cihazdaki bir tarayici.
 * Gonderim App\Services\Push\WebPush'ta; saglayici "artik yok" derse
 * (404/410) satir silinir.
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent', 'last_sent_at'];

    protected $casts = ['last_sent_at' => 'datetime'];

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
