<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Faz 2 / H (P17): uretimde istegin suresi ag, acilis ve veritabani olarak
 * ayrilamiyordu; yerel sorgu sayilari tek olcumdu. Server-Timing basligi
 * tarayicinin gelistirici aracinda bu ayrimi gosterir.
 *
 * Zamanlama bilgisi saldirgana da ipucu verir: yalnizca SERVER_TIMING=true
 * iken (ornegin bir onizleme ortaminda) eklenir.
 */
class ServerTimingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Oturum/ara katman yok: sayilan sorgular yalnizca bu rotanin sorgulari.
        Route::get('/_test/iki-sorgu', function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'tamam';
        });
    }

    public function test_the_header_is_off_by_default(): void
    {
        $this->get('/_test/iki-sorgu')->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_the_header_splits_boot_database_and_total_time(): void
    {
        config(['database.server_timing' => true]);

        $baslik = $this->get('/_test/iki-sorgu')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression(
            '/^boot;dur=\d+(\.\d+)?, db;dur=\d+(\.\d+)?;desc="2 sorgu", app;dur=\d+(\.\d+)?$/',
            (string) $baslik
        );
    }

    public function test_the_switch_reads_the_environment(): void
    {
        putenv('SERVER_TIMING=true');
        $this->refreshApplication();

        $this->assertTrue(config('database.server_timing'));

        putenv('SERVER_TIMING');
        $this->refreshApplication();

        $this->assertFalse(config('database.server_timing'));
    }
}
