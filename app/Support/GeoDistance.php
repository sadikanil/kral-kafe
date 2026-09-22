<?php

namespace App\Support;

/**
 * Iki koordinat arasi kus ucusu mesafe (haversine).
 *
 * Neden duz Oklid degil: enlem ve boylam DERECE, ve bir derece boylamin metre
 * karsiligi enleme gore degisiyor (ekvatorda ~111 km, kutupta 0). Dereceleri
 * dogrudan cikarmak Turkiye enleminde yaklasik %25 hata verir - ve hata
 * sessizdir, yalnizca yanlis sayi cikar.
 *
 * Kure yaklasimi bu is icin fazlasiyla yeterli: kafe ile ogrenci arasindaki
 * mesafeyi metre mertebesinde soylememiz gerekiyor, jeodezik dogruluk degil.
 */
class GeoDistance
{
    /** Dunya'nin ortalama yaricapi (metre). */
    private const YARICAP = 6_371_000;

    public static function metersBetween(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $enlemFarki = deg2rad($lat2 - $lat1);
        $boylamFarki = deg2rad($lng2 - $lng1);

        $a = sin($enlemFarki / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($boylamFarki / 2) ** 2;

        return self::YARICAP * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
