<?php

namespace App\Support;

use App\Enums\ExamType;
use App\Models\ExamResult;
use Illuminate\Support\Collection;

/**
 * Deneme netlerinin zaman serisi (Dalga 12b).
 *
 * DENEME TURUNE GORE AYRI: TYT ile AYT'nin ders listesi ve net araligi
 * farkli; ayni eksene koymak iki seriyi de okunamaz yapardi.
 *
 * NEDENSELLIK IDDIASI YOK (SS7-C): calisma suresi bu grafige bindirilmiyor.
 * "Cok calisti, neti artti" cikarimini sistem yapmaz.
 *
 * SS6.1-4 ile celismiyor: bu ogrencinin KENDI serisi, baska ogrencilerle
 * kiyas degil.
 */
class NetProgress
{
    /** Tek denemeden grafik cizilmez. */
    private const EN_AZ_NOKTA = 2;

    /**
     * @param  Collection<int,ExamResult>  $results
     * @return list<array{type:ExamType,labels:list<string>,series:list<array{name:string,points:list<float|null>}>,min:float,max:float}>
     */
    public static function fromResults(Collection $results): array
    {
        $grafikler = [];

        foreach (self::byType($results) as $tur => $sonuclar) {
            if ($sonuclar->count() < self::EN_AZ_NOKTA) {
                continue;
            }

            $seriler = self::series($sonuclar);
            $degerler = collect($seriler)
                ->flatMap(fn (array $seri) => $seri['points'])
                ->filter(fn (?float $d) => $d !== null);

            $grafikler[] = [
                'type' => ExamType::from((string) $tur),
                'labels' => $sonuclar->map(fn (ExamResult $s) => (string) $s->event->title)->values()->all(),
                'series' => $seriler,
                'min' => (float) ($degerler->min() ?? 0),
                'max' => (float) ($degerler->max() ?? 0),
            ];
        }

        return $grafikler;
    }

    /**
     * Tur basina sonuclar, ESKIDEN YENIYE.
     *
     * Liste ekranda yeniden eskiye siralaniyor; grafik ters yonde okunur,
     * o yuzden sira burada kuruluyor.
     *
     * @param  Collection<int,ExamResult>  $results
     * @return Collection<string,Collection<int,ExamResult>>
     */
    private static function byType(Collection $results): Collection
    {
        return $results
            ->filter(fn (ExamResult $sonuc) => $sonuc->event !== null)
            ->sortBy(fn (ExamResult $sonuc) => $sonuc->event->exam_date->toDateString())
            ->groupBy(fn (ExamResult $sonuc) => $sonuc->event->exam_type->value);
    }

    /**
     * Toplam net + ders basina seri.
     *
     * @param  Collection<int,ExamResult>  $sonuclar
     * @return list<array{name:string,points:list<float|null>}>
     */
    private static function series(Collection $sonuclar): array
    {
        $seriler = [[
            'name' => 'Toplam',
            'points' => $sonuclar->map(fn (ExamResult $s) => (float) $s->totalNet())->values()->all(),
        ]];

        // Ders adlari ILK gorulme sirasina gore; alfabetik siralamak
        // ekrandaki listeyle uyusmazdi.
        $dersAdlari = [];
        foreach ($sonuclar as $sonuc) {
            foreach ($sonuc->subjects as $satir) {
                $ad = (string) $satir->subject?->name;
                if ($ad !== '' && ! in_array($ad, $dersAdlari, true)) {
                    $dersAdlari[] = $ad;
                }
            }
        }

        foreach ($dersAdlari as $ad) {
            $seriler[] = [
                'name' => $ad,
                // O denemede olmayan ders BOSLUK birakir (null), sifir degil.
                'points' => $sonuclar->map(function (ExamResult $sonuc) use ($ad) {
                    $satir = $sonuc->subjects->first(fn ($s) => $s->subject?->name === $ad);

                    return $satir === null ? null : (float) $satir->net;
                })->values()->all(),
            ];
        }

        return $seriler;
    }
}
