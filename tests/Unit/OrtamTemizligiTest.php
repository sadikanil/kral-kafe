<?php

namespace Tests\Unit;

use App\Support\OrtamTemizligi;
use PHPUnit\Framework\TestCase;

class OrtamTemizligiTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TEMIZLIK_DENEME'], $_ENV['TEMIZLIK_DENEME']);
        putenv('TEMIZLIK_DENEME');

        parent::tearDown();
    }

    public function test_satir_sonlari_uc_kaynakta_birden_kirpilir(): void
    {
        $_SERVER['TEMIZLIK_DENEME'] = "deger\r\n";
        $_ENV['TEMIZLIK_DENEME'] = "deger\r\n";
        putenv("TEMIZLIK_DENEME=deger\r\n");

        $this->assertSame(['TEMIZLIK_DENEME'], OrtamTemizligi::satirSonlariniKirp());

        $this->assertSame('deger', $_SERVER['TEMIZLIK_DENEME']);
        $this->assertSame('deger', $_ENV['TEMIZLIK_DENEME']);
        $this->assertSame('deger', getenv('TEMIZLIK_DENEME'));

        // Ikinci cagri bir sey bulmaz: islem idempotan.
        $this->assertSame([], OrtamTemizligi::satirSonlariniKirp());
    }

    public function test_ortadaki_satir_sonu_ve_bosluklar_korunur(): void
    {
        $_SERVER['TEMIZLIK_DENEME'] = " ilk\nikinci ";

        $this->assertSame([], OrtamTemizligi::satirSonlariniKirp());
        $this->assertSame(" ilk\nikinci ", $_SERVER['TEMIZLIK_DENEME']);
    }
}
