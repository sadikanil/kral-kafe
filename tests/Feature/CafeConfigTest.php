<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class CafeConfigTest extends TestCase
{
    public function test_the_cafe_has_a_timezone_opening_and_closing_time(): void
    {
        $this->assertSame('Europe/Istanbul', config('kafe.timezone'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', config('kafe.acilis'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', config('kafe.kapanis'));
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
        $acilis = Carbon::createFromFormat('H:i', config('kafe.acilis'));
        $kapanis = Carbon::createFromFormat('H:i', config('kafe.kapanis'));
        $kafeGunuDakika = $acilis->diffInMinutes($kapanis);

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
