<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir ogrencinin bir deneme sonuc raporu (PDF + yapay zeka analizi).
 */
class ExamReport extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const DONE = 'done';
    public const FAILED = 'failed';

    protected $fillable = [
        'student_id',
        'exam_event_id',
        'title',
        'file_path',
        'uploaded_by',
        'status',
        'analysis',
        'error',
        'analyzed_at',
    ];

    protected $casts = [
        'analysis' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function examEvent(): BelongsTo
    {
        return $this->belongsTo(ExamEvent::class);
    }

    public function isAnalyzed(): bool
    {
        return $this->status === self::DONE && is_array($this->analysis);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DONE => 'Analiz hazır',
            self::FAILED => 'Analiz başarısız',
            default => 'Analiz bekliyor',
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::DONE => 'success',
            self::FAILED => 'danger',
            default => 'warning',
        };
    }

    public function fileName(): string
    {
        return \Illuminate\Support\Str::slug($this->title) . '.pdf';
    }
}
