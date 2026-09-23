<?php

namespace App\Support;

/**
 * Dakikayi okunur sureye cevirir: 125 -> "2 sa 5 dk", 120 -> "2 sa",
 * 35 -> "35 dk". Eskiden "2s 5dk" idi; "s" saniye gibi okunuyordu.
 *
 * Tek yerde: bicim panelde, raporda ve veli ekraninda ayni olmali. Blade
 * icinde intdiv/modulo tekrarlamak, bir ekranda "2.08 saat" yazilmasiyla
 * sonuclanir.
 */
class Duration
{
    public static function human(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $saat = intdiv($minutes, 60);
        $dakika = $minutes % 60;

        if ($saat === 0) {
            return "{$dakika} dk";
        }

        return $dakika === 0 ? "{$saat} sa" : "{$saat} sa {$dakika} dk";
    }
}
