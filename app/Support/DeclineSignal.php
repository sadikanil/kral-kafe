<?php

namespace App\Support;

/**
 * Tek bir dusus sinyali (Dalga 16).
 *
 * YORUMSUZ: yalnizca sayi ve olgu tasir. "Motivasyonu dusuk" demez,
 * "son 7 gunde 2 gelis, onceki 7 gunde 5" der - SS6.1-6 geregi sifati
 * insan uretir, sistem sayi gosterir.
 *
 * Saklanmaz, her bakista hesaplanir. Tablo, "ne zaman duzeldi" sorusunu
 * da yonetmeyi gerektirirdi.
 */
final class DeclineSignal
{
    public function __construct(
        /** attendance | absence | goal */
        public readonly string $kind,
        public readonly string $label,
        /** CSS rozet sinifi: warning ya da danger */
        public readonly string $severity,
    ) {
    }
}
