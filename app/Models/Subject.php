<?php

namespace App\Models;

use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ders/alan tanimi. VERIDEN gelir, koda gomulmez (Dalga 12).
 *
 * Dalga 30b: liste mufredattan (migration 2026_09_23_170000). grades ve
 * fields virgullu listeler; null = herkes.
 */
class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'exam_type', 'sort_order', 'is_active', 'code', 'grades', 'fields'];

    protected $casts = ['is_active' => 'bool'];

    /** DB varsayilani modele yansimaz; bkz. StudyTable::$attributes. */
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForExamType(Builder $query, string $tur): Builder
    {
        return $query->where('exam_type', $tur);
    }

    /** Konu listesi (Dalga 30b), ogretim sirasinda. */
    public function topics()
    {
        return $this->hasMany(SubjectTopic::class)->orderBy('sort_order');
    }

    /**
     * Ogrencinin sorumlu oldugu dersler: sinifina ve alanina gore.
     *
     * Bilinmeyen bilgi SUZMEZ: sinifi girilmemis ogrencide hicbir ders,
     * alani girilmemis 12. sinifta hicbir alan gizlenmez. Yanlis gizlemek,
     * kocun bir dersi hic goremedigi bir plan demekti.
     */
    public function scopeForStudent(Builder $query, User $ogrenci): Builder
    {
        $sinif = $ogrenci->gradeEnum();
        $alan = $sinif?->hasField() === false ? null : $ogrenci->fieldEnum();

        return $query->active()
            ->when($sinif, fn ($q) => self::csvIcerir($q, 'grades', $sinif->value))
            ->when($alan, fn ($q) => self::csvIcerir($q, 'fields', $alan->value))
            ->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Deneme sonucu formunun dersleri: denemenin turune ve ogrencinin
     * alanina gore (AYT'de sayisalci Edebiyat netini girmez).
     */
    public function scopeForExam(Builder $query, ExamType $tur, User $ogrenci): Builder
    {
        $turler = match ($tur) {
            ExamType::Tyt => ['tyt'],
            ExamType::Ayt => ['ayt'],
            ExamType::TytAyt => ['tyt', 'ayt'],
            default => null,
        };
        $alan = $ogrenci->fieldEnum();

        return $query->active()
            ->when($turler, fn ($q) => $q->whereIn('exam_type', $turler))
            ->when($alan, fn ($q) => self::csvIcerir($q, 'fields', $alan->value))
            ->orderBy('sort_order')->orderBy('name');
    }

    /** "sutun bos ya da virgullu listede deger var". || iki surucude de birlestirme. */
    private static function csvIcerir(Builder $q, string $sutun, string $deger): Builder
    {
        return $q->where(fn ($w) => $w->whereNull($sutun)
            ->orWhereRaw("(',' || {$sutun} || ',') LIKE ?", ["%,{$deger},%"]));
    }
}
