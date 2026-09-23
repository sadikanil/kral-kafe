<?php

namespace App\Models;

use App\Enums\StudyUnit;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Calisma kaydi (Dalga 28): ogrencinin kendi girdigi "ne bitirdim".
 *
 * Dogrulanmis bir sure DEGIL, ogrencinin beyani. Veli ve koc gorur; kimse
 * onaylamaz.
 */
class StudyLog extends Model
{
    protected $fillable = ['student_id', 'study_session_id', 'subject_id', 'amount', 'unit', 'note'];

    protected $casts = [
        'amount' => 'integer',
        'unit' => StudyUnit::class,
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }

    /** Yerel gunun (veya araligin) kayitlari; created_at UTC saklanir. */
    public function scopeBetweenLocalDays(Builder $query, string $ilk, string $son): Builder
    {
        return $query->whereBetween('created_at', [LocalDay::bounds($ilk)[0], LocalDay::bounds($son)[1]]);
    }

    /**
     * Birim basina toplam, birimlerin ekran sirasinda: ['soru' => 350, 'konu' => 3].
     *
     * @param Collection<int,StudyLog> $kayitlar
     * @return array<string,int>
     */
    public static function totals(Collection $kayitlar): array
    {
        $toplam = [];

        foreach (StudyUnit::cases() as $birim) {
            $adet = $kayitlar->filter(fn (StudyLog $k) => $k->unit === $birim)->sum('amount');
            if ($adet > 0) {
                $toplam[$birim->value] = (int) $adet;
            }
        }

        return $toplam;
    }

    /** Veli ve koc ekrani: son N yerel gunun kayitlari, yeniden eskiye. */
    public static function recentFor(User $ogrenci, int $gun = 14): Collection
    {
        $bugun = LocalDay::today();
        $ilk = \Illuminate\Support\Carbon::parse($bugun)->subDays($gun - 1)->toDateString();

        return static::where('student_id', $ogrenci->id)
            ->betweenLocalDays($ilk, $bugun)
            ->with('subject')
            ->latest()->orderByDesc('id')
            ->get();
    }

    /** "Tarih · 200 soru" - ders yoksa "Genel". */
    public function label(): string
    {
        return ($this->subject?->name ?? 'Genel') . ' · ' . $this->amount . ' ' . $this->unit->label();
    }
}
