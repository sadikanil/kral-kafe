<?php

namespace Tests\Unit;

use App\Support\Teshis;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TeshisTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['TESHIS_DENEME', 'DB_PASSWORD', 'APP_KEY', 'DB_HOST'] as $anahtar) {
            unset($_SERVER[$anahtar], $_ENV[$anahtar]);
            putenv($anahtar);
        }

        parent::tearDown();
    }

    public function test_bos_ya_da_yanlis_jeton_yetkili_degil(): void
    {
        $this->assertFalse(Teshis::yetkili(null));
        $this->assertFalse(Teshis::yetkili(''));
        $this->assertFalse(Teshis::yetkili('yanlis-jeton'));
    }

    public function test_satir_sonlari_uc_kaynakta_birden_kirpilir(): void
    {
        $_SERVER['TESHIS_DENEME'] = "deger\n";
        $_ENV['TESHIS_DENEME'] = "deger\n";
        putenv("TESHIS_DENEME=deger\n");

        $this->assertSame(['TESHIS_DENEME'], Teshis::satirSonlariniKirp());

        $this->assertSame('deger', $_SERVER['TESHIS_DENEME']);
        $this->assertSame('deger', $_ENV['TESHIS_DENEME']);
        $this->assertSame('deger', getenv('TESHIS_DENEME'));

        // Ikinci cagri bir sey bulmaz: islem idempotan.
        $this->assertSame([], Teshis::satirSonlariniKirp());
    }

    public function test_ortadaki_satir_sonu_korunur(): void
    {
        $_SERVER['TESHIS_DENEME'] = "ilk\nikinci";

        $this->assertSame([], Teshis::satirSonlariniKirp());
        $this->assertSame("ilk\nikinci", $_SERVER['TESHIS_DENEME']);
    }

    public function test_ozet_sirlarin_degerini_degil_uzunlugunu_gosterir(): void
    {
        $_SERVER['DB_PASSWORD'] = "gizli-sifre\n";
        $_SERVER['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
        $_SERVER['DB_HOST'] = "sunucu\x00adi";

        $ozet = implode("\n", Teshis::ortamOzeti());

        $this->assertStringNotContainsString('gizli-sifre', $ozet);
        $this->assertStringContainsString('DB_PASSWORD=var (12 karakter, SATIR SONU ICERIYOR', $ozet);
        $this->assertStringContainsString('APP_KEY=var (51 karakter, cozulmus 32 bayt)', $ozet);
        $this->assertStringContainsString('DB_HOST=sunucu adi', $ozet);
    }

    public function test_hata_ozeti_sinif_mesaj_ve_konum_verir(): void
    {
        $hata = new RuntimeException("dis\nhata", 0, new RuntimeException('ic hata'));

        $ozet = Teshis::hataOzeti($hata);

        $this->assertStringContainsString('RuntimeException: dis hata @ tests/Unit/TeshisTest.php', $ozet);
        $this->assertStringContainsString('<- RuntimeException: ic hata', $ozet);
    }
}
