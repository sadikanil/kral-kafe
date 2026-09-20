<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aylik takvim izgarasi: pazartesi baslayan tam haftalar.
 *
 * Gorunum uc yerde (yonetici, ogrenci, veli) ayni; izgara hesabi Blade'e
 * gomulseydi uc kopya olurdu.
 */
final class ExamCalendar
{
    /**
     * ?ay=YYYY-MM parametresini guvenle yil/ay'a cevirir; bozuk ya da bos
     * deger kafe gununun ayina duser.
     *
     * @return array{0:int,1:int}
     */
    public static function parseMonth(?string $ay): array
    {
        if (is_string($ay) && preg_match('/^(\d{4})-(\d{2})$/', $ay, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return [(int) $m[1], (int) $m[2]];
        }

        $bugun = Carbon::parse(LocalDay::today());

        return [$bugun->year, $bugun->month];
    }

    /**
     * @param  Collection<int,\App\Models\ExamEvent>  $events  ayin denemeleri
     * @return list<list<array{date:string,day:int,inMonth:bool,isToday:bool,events:Collection}>>
     */
    public static function weeks(int $year, int $month, Collection $events): array
    {
        $ayBasi = Carbon::create($year, $month, 1);
        $gun = $ayBasi->copy()->startOfWeek(Carbon::MONDAY);
        $son = $ayBasi->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $bugun = LocalDay::today();
        $gruplu = $events->groupBy(fn ($e) => $e->dateKey());

        $haftalar = [];
        $hafta = [];

        while ($gun->lessThanOrEqualTo($son)) {
            $tarih = $gun->toDateString();
            $hafta[] = [
                'date' => $tarih,
                'day' => $gun->day,
                'inMonth' => $gun->month === $month,
                'isToday' => $tarih === $bugun,
                'events' => $gruplu->get($tarih, collect()),
            ];

            if (count($hafta) === 7) {
                $haftalar[] = $hafta;
                $hafta = [];
            }

            $gun->addDay();
        }

        return $haftalar;
    }

    public static function monthLabel(int $year, int $month): string
    {
        return Carbon::create($year, $month, 1)->locale('tr')->translatedFormat('F Y');
    }

    /** @return array{prev:string,next:string} ?ay= degerleri */
    public static function neighbours(int $year, int $month): array
    {
        $ay = Carbon::create($year, $month, 1);

        return [
            'prev' => $ay->copy()->subMonth()->format('Y-m'),
            'next' => $ay->copy()->addMonth()->format('Y-m'),
        ];
    }
}
