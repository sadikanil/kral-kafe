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
        $this->nodeTesti('tests/js/kabuk.test.mjs');
    }

    /** Service worker (Faz 4): hangi istek onbellege girer, hangisi hic girmez. */
    public function test_the_service_worker_routes_requests(): void
    {
        $this->nodeTesti('tests/js/sw.test.mjs');
    }

    /** Odak modu (Faz 4): pil dostu ekran kilidi ve ayrilis sayimi. */
    public function test_the_focus_mode_script_behaves(): void
    {
        $this->nodeTesti('tests/js/odak.test.mjs');
    }

    private function nodeTesti(string $dosya): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('node yok; tests/js calistirilamadi.');
        }

        // Dosya dogrudan calistirilir: "node --test" alt surec actigi icin
        // PHP'nin borulariyla burada ~14 sn bekliyordu; boylesi 0,2 sn.
        $surec = new Process([$node, base_path($dosya)], base_path());
        $surec->run();

        $this->assertTrue($surec->isSuccessful(), $surec->getOutput() . $surec->getErrorOutput());
    }
}
