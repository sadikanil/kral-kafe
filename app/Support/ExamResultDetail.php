<?php

namespace App\Support;

use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use Illuminate\Support\Collection;

/**
 * Deneme sonucu detay sayfasinin verisi (1 Ekim 2026).
 *
 * Ogrenci, veli, koc ve yonetici AYNI sayfayi gorur; fark yalnizca geri
 * donus adresi ve kocun "plana ekle" baglantisi. Hesap burada, gorunum
 * sayi yazar - sifat yok (README SS6.1-6).
 *
 * Onceki deneme: ayni ogrencinin, ayni turdeki (TYT/AYT) bir onceki
 * sonucu. Degisim yalnizca o denemeye gore; uzun donem egilimi net
 * grafiginde.
 */
final class ExamResultDetail
{
    /**
     * @return array{
     *   result: ExamResult, previous: ?ExamResult, netChange: ?float,
     *   subjects: list<array{name:string,correct:int,wrong:int,blank:int,net:float,change:?float}>,
     *   ranks: array<string,string>, weak: list<array>, topicGroups: array<string,list<array>>
     * }
     */
    public static function for(ExamResult $sonuc): array
    {
        $sonuc->loadMissing(['event', 'subjects.subject', 'student']);
        $onceki = self::previous($sonuc);
        $oncekiNetler = $onceki?->subjects->mapWithKeys(fn (ExamResultSubject $s) => [$s->subject_id => $s->net]) ?? collect();

        return [
            'result' => $sonuc,
            'previous' => $onceki,
            'netChange' => $onceki ? round($sonuc->totalNet() - $onceki->totalNet(), 2) : null,
            'subjects' => $sonuc->subjects
                ->sortBy(fn (ExamResultSubject $s) => $s->subject?->sort_order ?? $s->subject_id)
                ->map(fn (ExamResultSubject $s) => [
                    'name' => $s->subject?->name ?? '—',
                    'correct' => (int) $s->correct,
                    'wrong' => (int) $s->wrong,
                    'blank' => (int) $s->blank,
                    'net' => $s->net,
                    'change' => $oncekiNetler->has($s->subject_id) ? round($s->net - $oncekiNetler[$s->subject_id], 2) : null,
                ])->values()->all(),
            'ranks' => collect([
                'Kurum' => $sonuc->rankLabel('institution'),
                'İlçe' => $sonuc->rankLabel('district'),
                'İl' => $sonuc->rankLabel('city'),
                'Türkiye' => $sonuc->rankLabel('country'),
            ])->filter()->all(),
            'weak' => $sonuc->weakTopics(),
            'topicGroups' => self::topicGroups($sonuc->topics ?? []),
        ];
    }

    public static function previous(ExamResult $sonuc): ?ExamResult
    {
        return ExamResult::where('student_id', $sonuc->student_id)
            ->whereKeyNot($sonuc->id)
            ->whereHas('event', fn ($q) => $q
                ->where('exam_type', $sonuc->event->exam_type)
                ->where('exam_date', '<', $sonuc->event->exam_date->toDateString()))
            ->with(['event', 'subjects'])
            ->get()
            ->sortByDesc(fn (ExamResult $r) => $r->event->exam_date)
            ->first();
    }

    /**
     * Konu satirlari derse gore gruplu, her satira basari yuzdesi ve eksik
     * isareti eklenmis. Ders icinde en dusuk basari once.
     *
     * @return array<string,list<array>>
     */
    public static function topicGroups(array $konular): array
    {
        return collect($konular)
            ->map(fn (array $k) => $k + ['success' => ExamTopics::success($k), 'weak' => ExamTopics::isWeak($k)])
            ->groupBy(fn (array $k) => $k['subject'] ?: 'Diğer')
            ->map(fn (Collection $g) => $g->sortBy('success')->values()->all())
            ->all();
    }

    /** "+3,25" / "−1,50" / "0,00" - degisim etiketi. */
    public static function signed(float $deger): string
    {
        $metin = number_format(abs($deger), 2, ',', '.');

        return match (true) {
            $deger > 0 => '+' . $metin,
            $deger < 0 => '−' . $metin,
            default => $metin,
        };
    }
}
