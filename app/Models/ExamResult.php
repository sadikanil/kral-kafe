<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir ogrencinin bir denemedeki sonucu (Dalga 12).
 *
 * Siralamalar nullable ve KATILIMCI SAYISIYLA birlikte: "1.240 kiside 87."
 * anlamli, ciplak "87." degil.
 */
class ExamResult extends Model
{
    protected $fillable = [
        'exam_event_id',
        'student_id',
        'rank_institution', 'total_institution',
        'rank_district', 'total_district',
        'rank_city', 'total_city',
        'rank_country', 'total_country',
        'note',
        'entered_by',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(ExamEvent::class, 'exam_event_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamResultSubject::class);
    }

    /** Ders netlerinin toplami. */
    public function totalNet(): float
    {
        return round($this->subjects->sum(fn (ExamResultSubject $satir) => $satir->net), 2);
    }

    /**
     * Siralamayi okunur bicimde: "1.240 kiside 87." Katilimci sayisi yoksa
     * yalnizca sira; ikisi de yoksa null.
     */
    public function rankLabel(string $alan): ?string
    {
        $sira = $this->{"rank_{$alan}"};

        if ($sira === null) {
            return null;
        }

        $toplam = $this->{"total_{$alan}"};

        return $toplam === null
            ? number_format($sira, 0, ',', '.') . '.'
            : number_format($toplam, 0, ',', '.') . ' kişide ' . number_format($sira, 0, ',', '.') . '.';
    }
}
