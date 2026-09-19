<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Rapor sayfalarinin URL'den aldigi yil/ay parametresini guvenli hale getirir.
 *
 * Ham istek girdisi dogrudan Carbon'a ya da whereMonth'a verildiginde
 * "?year=abc" gibi bir adres 500 uretiyordu.
 */
class Period
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;

    /**
     * @return array{0:int,1:int} [yil, ay]
     */
    public static function normalize(mixed $year, mixed $month): array
    {
        $now = Carbon::now();

        $year = filter_var($year, FILTER_VALIDATE_INT);
        $month = filter_var($month, FILTER_VALIDATE_INT);

        if ($year === false || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            $year = $now->year;
        }

        if ($month === false || $month < 1 || $month > 12) {
            $month = $now->month;
        }

        return [$year, $month];
    }
}
