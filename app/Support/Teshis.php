<?php

namespace App\Support;

use Throwable;

/**
 * GECICI canli teshis yardimcisi. Vercel'de APP_DEBUG kapaliyken 500'un
 * sebebi gorunmuyor; bu sinif api/index.php'deki jetonla kilitli rapora
 * sir icermeyen bir ortam ozeti ve hata ozeti saglar.
 *
 * Depo public oldugu icin jetonun kendisi burada YOK, yalnizca sha256 ozeti.
 * Jeton yalnizca sohbette paylasilir; is bitince bu sinif ve api/index.php
 * icindeki rapor kaldirilir.
 *
 * Ayrica panelden yapistirilan degerlerin sonundaki satir sonunu temizler:
 * DB_PASSWORD'de gorulen "\n" ayni sekilde APP_KEY ya da DB_HOST'a da
 * yapismis olabilir; ikisi de veritabanina hic ulasmadan 500 uretir.
 */
final class Teshis
{
    private const JETON_OZETI = '25bb73d02e570ce40ba005da90b1964570af76721c1e6bf39775aadbe00f7b5e';

    /** Degeri asla gosterilmeyecek, yalnizca uzunlugu bildirilecek anahtarlar. */
    private const SIRLAR = [
        'APP_KEY', 'DB_PASSWORD', 'DB_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
        'OPENAI_API_KEY', 'ADMIN_PASSWORD', 'MAIL_PASSWORD', 'REDIS_PASSWORD',
    ];

    /** Raporda gosterilecek, sir olmayan anahtarlar. */
    private const GOSTERILENLER = [
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_SSLMODE', 'DB_EMULATE_PREPARES',
        'SESSION_DRIVER', 'SESSION_LIFETIME', 'CACHE_STORE', 'QUEUE_CONNECTION', 'LOG_CHANNEL',
        'UPLOAD_DISK', 'FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_URL', 'AWS_DEFAULT_REGION', 'AWS_USE_PATH_STYLE_ENDPOINT',
        'KAFE_TIMEZONE', 'KAFE_ACILIS', 'KAFE_KAPANIS',
        'VIEW_COMPILED_PATH', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_EVENTS_CACHE', 'APP_ROUTES_CACHE',
    ];

    public static function yetkili(?string $jeton): bool
    {
        return is_string($jeton)
            && $jeton !== ''
            && hash_equals(self::JETON_OZETI, hash('sha256', $jeton));
    }

    /**
     * Tum ortam degiskenlerinin bas ve sonundaki satir sonlarini kirpar.
     * Degeri degisen anahtarlarin adlarini dondurur (degerleri degil).
     *
     * @return list<string>
     */
    public static function satirSonlariniKirp(): array
    {
        $duzeltilenler = [];

        foreach (self::tumOrtam() as $anahtar => $deger) {
            $temiz = trim($deger, "\r\n");

            if ($temiz === $deger) {
                continue;
            }

            $_SERVER[$anahtar] = $temiz;
            $_ENV[$anahtar] = $temiz;
            putenv("{$anahtar}={$temiz}");
            $duzeltilenler[] = $anahtar;
        }

        return $duzeltilenler;
    }

    /**
     * Sir icermeyen ortam ozeti; her satir "ANAHTAR=aciklama".
     *
     * @return list<string>
     */
    public static function ortamOzeti(): array
    {
        $ortam = self::tumOrtam();
        $satirlar = [];

        foreach (self::GOSTERILENLER as $anahtar) {
            $satirlar[] = array_key_exists($anahtar, $ortam)
                ? "{$anahtar}=" . self::temizle($ortam[$anahtar])
                : "{$anahtar}=(tanimsiz)";
        }

        foreach (self::SIRLAR as $anahtar) {
            if (! array_key_exists($anahtar, $ortam)) {
                $satirlar[] = "{$anahtar}=(tanimsiz)";
                continue;
            }

            $satirlar[] = "{$anahtar}=" . self::sirOzeti($anahtar, $ortam[$anahtar]);
        }

        return $satirlar;
    }

    public static function hataOzeti(Throwable $hata): string
    {
        $satirlar = [];
        $derinlik = 0;

        for ($h = $hata; $h !== null && $derinlik < 4; $h = $h->getPrevious(), $derinlik++) {
            $satirlar[] = sprintf(
                '%s%s: %s @ %s:%d',
                $derinlik ? '  <- ' : '',
                $h::class,
                self::temizle($h->getMessage()),
                self::kisaYol($h->getFile()),
                $h->getLine(),
            );
        }

        foreach (array_slice($hata->getTrace(), 0, 6) as $kare) {
            $satirlar[] = sprintf(
                '    %s%s%s (%s:%d)',
                $kare['class'] ?? '',
                $kare['type'] ?? '',
                $kare['function'] ?? '?',
                self::kisaYol($kare['file'] ?? '?'),
                $kare['line'] ?? 0,
            );
        }

        return implode("\n", $satirlar);
    }

    /** Yalnizca gorunur ASCII kalir; satir sonu ve kontrol karakterleri bosluga doner. */
    public static function temizle(string $deger): string
    {
        return preg_replace('/[^\x20-\x7E]/', ' ', $deger) ?? '';
    }

    private static function sirOzeti(string $anahtar, string $deger): string
    {
        $uzunluk = strlen($deger);
        $ozet = "var ({$uzunluk} karakter";

        if (preg_match('/[\r\n]/', $deger)) {
            $ozet .= ', SATIR SONU ICERIYOR';
        }

        if ($deger !== trim($deger)) {
            $ozet .= ', bas/son bosluk var';
        }

        if ($anahtar === 'APP_KEY') {
            if (str_starts_with($deger, 'base64:')) {
                $cozulmus = base64_decode(substr($deger, 7), true);
                $ozet .= $cozulmus === false
                    ? ', base64 COZULEMEDI'
                    : ', cozulmus ' . strlen($cozulmus) . ' bayt' . (strlen($cozulmus) === 32 ? '' : ' - 32 OLMALI');
            } else {
                $ozet .= ', base64: on eki YOK';
            }
        }

        return $ozet . ')';
    }

    /** @return array<string,string> */
    private static function tumOrtam(): array
    {
        $ortam = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $kaynak) {
            foreach ($kaynak as $anahtar => $deger) {
                if (is_string($anahtar) && is_string($deger)) {
                    $ortam[$anahtar] = $deger;
                }
            }
        }

        return $ortam;
    }

    private static function kisaYol(string $yol): string
    {
        $kok = dirname(__DIR__, 2) . '/';

        return str_starts_with($yol, $kok) ? substr($yol, strlen($kok)) : $yol;
    }
}
