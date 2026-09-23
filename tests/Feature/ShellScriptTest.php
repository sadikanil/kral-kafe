<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * public/js/kabuk.js (cift gonderim kilidi, menu, zil, hata ozeti) mantigi
 * tests/js altinda Node'un kendi test calistiricisiyla sinanir; projede JS
 * test paketi yok, jsdom da gerekmiyor. Bu sinif onu PHPUnit'e baglar ki
 * "php artisan test" betik bozuldugunda da kirmizi yansin.
 */
class ShellScriptTest extends TestCase
{
    public function test_the_shell_script_behaves(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('node yok; tests/js calistirilamadi.');
        }

        // Dosya dogrudan calistirilir: "node --test" alt surec actigi icin
        // PHP'nin borulariyla burada ~14 sn bekliyordu; boylesi 0,2 sn.
        $surec = new Process([$node, base_path('tests/js/kabuk.test.mjs')], base_path());
        $surec->run();

        $this->assertTrue($surec->isSuccessful(), $surec->getOutput() . $surec->getErrorOutput());
    }
}
