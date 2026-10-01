<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PDF'teki bir ogrenci satiri: adi, sinifi, karnesinin sayfasi, eslendigi
 * ogrenci ve okunan veri (dersler, siralar, konular).
 */
class ExamImportRow extends Model
{
    public const AUTO = 'auto';
    public const SUGGESTED = 'suggested';
    public const MANUAL = 'manual';
    public const NONE = 'none';
    public const SKIP = 'skip';

    protected $fillable = ['exam_import_id', 'name', 'class_label', 'card_page', 'card_read', 'student_id', 'match', 'data'];

    protected $casts = [
        'data' => 'array',
        'card_read' => 'boolean',
        'card_page' => 'integer',
    ];

    protected $attributes = ['match' => self::NONE, 'card_read' => false];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ExamImport::class, 'exam_import_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** Yayinlanacak mi: bir ogrenciye bagli ve atlanmamis. */
    public function isPublishable(): bool
    {
        return $this->student_id !== null && $this->match !== self::SKIP;
    }

    public function matchLabel(): string
    {
        return match ($this->match) {
            self::AUTO => 'Eşleşti',
            self::SUGGESTED => 'Kontrol et',
            self::MANUAL => 'Elle seçildi',
            self::SKIP => 'Atlandı',
            default => 'Eşleşmedi',
        };
    }

    public function matchBadge(): string
    {
        return match ($this->match) {
            self::AUTO, self::MANUAL => 'success',
            self::SUGGESTED => 'warning',
            self::SKIP => 'info',
            default => 'danger',
        };
    }
}
