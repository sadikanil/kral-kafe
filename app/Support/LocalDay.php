<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Gun, hafta ve ay sinirlarini KAFENIN yerel saatine gore kurar.
 *
 * Neden gerekli: uygulama UTC'de calisiyor (config/app.php), kafe ise
 * Istanbul'da (UTC+3). whereDate/whereMonth/whereYear UTC gunune gore
 * calistigi icin yerel 00:00-03:00 arasindaki her kayit bir onceki gune,
 * ay sinirindaysa bir onceki AYA dusuyor - gunun %12,5'i yanlis kovada.
 *
 * Bu sinifin dondurdugu sinirlar whereBetween ile kullanilir; boylece
 * sorgu sutuna fonksiyon uygulamaz ve indeks kullanilabilir kalir.
 */
class LocalDay
{
    public static function timezone(): string
    {
        return config('kafe.timezone');
    }

    /**
     * Bir anin ait oldugu YEREL gun (Y-m-d).
     */
    public static function of(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::timezone())->toDateString();
    }

    /**
     * Kafenin saatine gore bugun (Y-m-d).
     */
    public static function today(): string
    {
        return Carbon::now(self::timezone())->toDateString();
    }

    /**
     * Bir yerel gunun baslangic ve bitis ani.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function bounds(string $date): array
    {
        $gun = Carbon::parse($date, self::timezone())->startOfDay();

        return [$gun, $gun->copy()->endOfDay()];
    }

    /**
     * Verilen gunun icinde bulundugu haftanin sinirlari (pazartesi baslar).
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function weekBounds(string $date): array
    {
        $gun = Carbon::parse($date, self::timezone());

        return [
            $gun->copy()->startOfWeek(Carbon::MONDAY),
            $gun->copy()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    /**
     * Bir yerel ayin sinirlari.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function monthBounds(int $year, int $month): array
    {
        $bas = Carbon::create($year, $month, 1, 0, 0, 0, self::timezone());

        return [$bas, $bas->copy()->endOfMonth()];
    }
}
