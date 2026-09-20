<?php

namespace App\Support;

/**
 * Dakikayi okunur sureye cevirir: 125 -> "2s 5dk".
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

        return intdiv($minutes, 60) . 's ' . ($minutes % 60) . 'dk';
    }
}
