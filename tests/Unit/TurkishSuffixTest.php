<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UX turu (23 Eyl): degisken bir degerin ardina ek elle yazilamaz.
 * Ek sese gore degisir: "10:00'dan", "13:35'ten", "30 Kasım'a" - sabit
 * "'den beri" ya da "'e kadar" cogu degerde yanlis okunuyordu. Ekten
 * bagimsiz bicim kullanilir: "giriş 13:35", "bitiş 30 Eylül".
 */
class TurkishSuffixTest extends TestCase
{
    public function test_no_view_glues_a_suffix_onto_an_echo(): void
    {
        $bulunan = [];
        $gezgin = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/resources/views'));

        foreach ($gezgin as $dosya) {
            if (! $dosya->isFile() || ! str_ends_with($dosya->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($dosya->getPathname()) as $no => $satir) {
                if (preg_match("/\}\}'[a-zçğıöşü]/u", $satir)) {
                    $bulunan[] = $dosya->getFilename() . ':' . ($no + 1);
                }
            }
        }

        $this->assertSame([], $bulunan, 'Degiskene elle ek yazilmis: ' . implode(', ', $bulunan));
    }
}
