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

    /**
     * Bildirimin acacagi sayfa (5 Ekim 2026), alicinin gozunden. Deneme
     * sonucu: ogrenci kendi detayina (deneme kulubu varsa), veli cocugunun,
     * koc atandigi ogrencinin detayina. Deneme okuma (6 Ekim 2026): yonetici
     * aktarim sayfasina. Erisemeyecegi sayfaya baglanti verilmez (null).
     */
    public function url(User $alici): ?string
    {
        if ($this->related_id === null) {
            return null;
        }

        if ($this->type === NotificationType::ExamImport) {
            return $alici->isAdmin() ? route('admin.exam-imports.show', $this->related_id) : null;
        }

        if ($this->type !== NotificationType::ExamResult || $this->student === null) {
            return null;
        }

        $ogrenci = $this->student;

        return match (true) {
            $alici->id === $ogrenci->id => $alici->entitlements()->examClub
                ? route('user.exam-results.show', $this->related_id) : null,
            $alici->isParentOf($ogrenci) => route('parent.exam-result', [$ogrenci, $this->related_id]),
            $alici->canCoach($ogrenci) => route('coach.exams.result', $this->related_id),
            default => null,
        };
    }

    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
