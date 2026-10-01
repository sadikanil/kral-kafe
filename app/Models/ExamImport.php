<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kurum geneli deneme sonuc PDF'i (1 Ekim 2026).
 *
 * Durum makinesi (her adim Vercel'in 60 sn sinirina sigsin diye ayri istek):
 *   uploaded  -> dizin okunur (sinav adi, katilimci sayilari, ogrenci listesi)
 *   reading   -> karneler sayfa sayfa okunur
 *   review    -> yonetici eslesmeleri kontrol eder
 *   published -> sonuclar ogrenci panellerinde
 *   failed    -> hata; "Yeniden dene" kaldigi adimdan surer
 *
 * PDF TUM kurumu tasir; yalnizca yonetici indirir (README SS6.1-4).
 */
class ExamImport extends Model
{
    public const UPLOADED = 'uploaded';
    public const READING = 'reading';
    public const REVIEW = 'review';
    public const PUBLISHED = 'published';
    public const FAILED = 'failed';

    protected $fillable = ['exam_event_id', 'file_path', 'status', 'provider', 'meta', 'error', 'uploaded_by', 'published_at'];

    protected $casts = [
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::UPLOADED];

    public function event(): BelongsTo
    {
        return $this->belongsTo(ExamEvent::class, 'exam_event_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ExamImportRow::class)->orderBy('id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Islenecek adim var mi (dizin ya da okunmamis karne)? */
    public function isProcessing(): bool
    {
        return in_array($this->status, [self::UPLOADED, self::READING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::UPLOADED => 'Okunmayı bekliyor',
            self::READING => 'Karneler okunuyor',
            self::REVIEW => 'Kontrol bekliyor',
            self::PUBLISHED => 'Yayında',
            self::FAILED => 'Hata',
            default => $this->status,
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::PUBLISHED => 'success',
            self::FAILED => 'danger',
            self::REVIEW => 'warning',
            default => 'info',
        };
    }
}
