<?php

namespace App\Support;

/**
 * Surumlu varlik adresi. Surum dosyanin ozeti: dosya degisince adres de
 * degisir ve vercel.json ?v= tasiyan istegi bir yil onbellekte tutar.
 * Dosya pakette yoksa parametre eklenmez; tarayici her seferinde sorar,
 * bayat dosya riski yok.
 *
 * Ozet istek basina bir kez: simge sprite'i bir sayfada onlarca kez istenir.
 */
final class Asset
{
    /** @var array<string,string> */
    private static array $onbellek = [];

    public static function url(string $yol): string
    {
        return self::$onbellek[$yol] ??= asset($yol) . (is_file($dosya = public_path($yol))
            ? '?v=' . substr(md5_file($dosya), 0, 12)
            : '');
    }
}
