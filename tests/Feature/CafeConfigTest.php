<?php

namespace Tests\Feature;

use Tests\TestCase;

class CafeConfigTest extends TestCase
{
    /** Kapanis saati 1 Ekim 2026'da kalkti (esnek); yalnizca acilis var. */
    public function test_the_cafe_has_a_timezone_and_an_opening_time_but_no_fixed_closing(): void
    {
        $this->assertSame('Europe/Istanbul', config('kafe.timezone'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', config('kafe.acilis'));
        $this->assertNull(config('kafe.kapanis'));
    }

    public function test_the_application_timezone_stays_utc(): void
    {
        // config/app.php'yi Europe/Istanbul yapmak cazip ama yikici: Postgres
        // sutunlari "timestamp without time zone" oldugu icin kayitli TUM
        // satirlar sessizce 3 saat kayarak yeniden yorumlanir.
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_the_login_session_outlives_a_full_day_at_the_cafe(): void
    {
        // Kapanis esnek (gun sonu); bir oturum en fazla azami_saat surer.
        $kafeGunuDakika = (int) config('kafe.azami_saat') * 60;

        $this->assertGreaterThanOrEqual(
            $kafeGunuDakika,
            (int) config('session.lifetime'),
            'Giris cerezi kafe gununden kisa: ogrenci sabah oturum acip aksam '
            . '"Calismayi Bitir"e basmak istediginde giris ekranina duser ve '
            . 'kendi oturumunu kapatamaz'
        );
    }

    public function test_the_ip_gate_is_disabled_until_the_cafe_ip_is_known(): void
    {
        // Yanlis ya da bilinmeyen bir IP ile kapi sert acilirsa ilk gun
        // hicbir ogrenci oturum acamaz. Varsayilan fail-open + anomali isareti.
        $this->assertSame([], config('kafe.izinli_ipler'));
        $this->assertFalse(config('kafe.ip_zorunlu'));
    }
}
