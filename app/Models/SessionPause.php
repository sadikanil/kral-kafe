<?php

namespace App\Models;

use App\Enums\PauseKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Dalga 23: calisma oturumunda bir duraklama. */
class SessionPause extends Model
{
    protected $fillable = ['study_session_id', 'kind', 'started_at', 'ended_at'];

    protected $casts = [
        'kind' => PauseKind::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }

    /** Verilen ana kadar bu duraklamada gecen saniye (acik ise ana kadar). */
    public function secondsUntil(Carbon $an): int
    {
        $bitis = $this->ended_at && $this->ended_at->lessThan($an) ? $this->ended_at : $an;

        return max(0, (int) $this->started_at->diffInSeconds($bitis, false));
    }

    /** Sureli molada kalan saniye; sure dolduysa 0, suresizse null. */
    public function secondsLeft(?Carbon $an = null): ?int
    {
        $plan = $this->kind->plannedMinutes();

        return $plan === null ? null : max(0, $plan * 60 - $this->secondsUntil($an ?? now()));
    }
}
