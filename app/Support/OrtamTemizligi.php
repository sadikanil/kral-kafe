<?php

namespace App\Support;

/**
 * Panelden yapistirilan ortam degiskenlerinin sonundaki satir sonunu temizler.
 *
 * Vercel'in alanina bir degeri yapistirip Enter'a basmak degerin sonuna "\n"
 * ekliyor ve deger hangi anahtardaysa orayi SESSIZCE bozuyor:
 *
 *   DB_PASSWORD  -> "password authentication failed" (20 Eylul 2026'da yasandi:
 *                   13 karakterlik sifre canlida 15 karakter olculdu)
 *   APP_KEY      -> "Unsupported cipher or incorrect key length"
 *   DB_HOST      -> "could not translate host name"
 *
 * Laravel bootlanmadan once cagrilir; yalnizca bas ve sondaki \r\n kirpilir,
 * ortadaki satir sonlari (cok satirli PEM gibi) korunur. Mesru hicbir deger
 * satir sonuyla bitmez.
 */
final class OrtamTemizligi
{
    /**
     * Degeri degisen anahtarlarin adlarini dondurur (degerleri degil).
     *
     * @return list<string>
     */
    public static function satirSonlariniKirp(): array
    {
        $duzeltilenler = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $kaynak) {
            foreach ($kaynak as $anahtar => $deger) {
                if (! is_string($anahtar) || ! is_string($deger)) {
                    continue;
                }

                $temiz = trim($deger, "\r\n");

                if ($temiz === $deger) {
                    continue;
                }

                $_SERVER[$anahtar] = $temiz;
                $_ENV[$anahtar] = $temiz;
                putenv("{$anahtar}={$temiz}");
                $duzeltilenler[$anahtar] = true;
            }
        }

        return array_keys($duzeltilenler);
    }
}
