<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir deneme sonucunun tek dersteki kirilimi (Dalga 12).
 */
class ExamResultSubject extends Model
{
    use HasFactory;

    protected $fillable = ['exam_result_id', 'subject_id', 'correct', 'wrong', 'blank'];

    protected $attributes = ['correct' => 0, 'wrong' => 0, 'blank' => 0];

    public function result(): BelongsTo
    {
        return $this->belongsTo(ExamResult::class, 'exam_result_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Net = dogru - yanlis/4.
     *
     * HESAPLANIR, saklanmaz. Sutunda tutmak ikinci bir dogruluk kaynagi
     * yaratirdi: dogru/yanlis duzeltilip net guncellenmezse ikisi sessizce
     * ayrisir ve hangisinin dogru oldugu sorusu cevapsiz kalir.
     *
     * Negatif olabilir ve KIRPILMAZ: 1 dogru 8 yanlis gercekten -1 nettir,
     * sifira yuvarlamak ogrencinin durumunu oldugundan iyi gosterirdi.
     */
    public function getNetAttribute(): float
    {
        return round((int) $this->correct - ((int) $this->wrong / 4), 2);
    }
}
