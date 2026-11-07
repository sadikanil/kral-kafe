<?php

namespace App\Support;

/**
 * Calisan PHP php2shell yamasini tasiyor mu (7 Kasim 2026).
 *
 * CVE-2026-109618 / CVE-2026-109619: PHP cekirdeginde kimlik dogrulamasiz
 * uzaktan kod calistirma. Yamalar 21 Ekim 2026'da her dal icin ayri surumle
 * cikti. composer update bunu kapatmaz: sorun calisma zamaninda, vendor'da
 * degil.
 *
 * Iki yerde kullaniliyor:
 *  - Vercel derlemesi: composer.json scripts.vercel icindeki
 *    "kafe:php-surumu --kesin" yamasiz PHP'de derlemeyi durdurur; canli eski
 *    dagitimda kalir, yamasiz yenisi yayina girmez.
 *  - Yonetim > Ayarlar: expose_php kapali oldugu icin surum artik yanit
 *    basliginda yok; yonetici surumu orada gorur.
 *
 * Debian/Ubuntu paketleri yamayi geri tasir ama surum numarasini degistirmez
 * (orn. 8.3.6 yamali olabilir). Orada bu kontrol yanlis alarm verir; paket
 * surumune bakilir (docs/security/php2shell.md).
 */
final class PhpSurumu
{
    /** Dal => yamanin geldigi ilk surum. */
    public const YAMALI = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    public static function simdiki(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    public static function yamaliMi(?string $surum = null): bool
    {
        $surum ??= self::simdiki();
        $parcalar = explode('.', $surum);
        $dal = (int) $parcalar[0] . '.' . (int) ($parcalar[1] ?? 0);

        if (isset(self::YAMALI[$dal])) {
            return version_compare($surum, self::YAMALI[$dal], '>=');
        }

        // Yamadan sonra acilan dallar yamayla dogar; 8.2 oncesi destek disi.
        return version_compare($surum, '8.6.0', '>=');
    }
}
