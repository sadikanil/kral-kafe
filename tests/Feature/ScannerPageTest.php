<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Uygulama ici QR okuyucu (QA a11y A4).
 *
 * iPhone Safari'de BarcodeDetector yok: ana dugme "Calismaya basla" her
 * iPhone'lu ogrenciyi elle kod yazmaya itiyordu. Android'de ise okunan HER
 * QR adresine gidiliyordu - masadaki QR'in ustune yapistirilan bir etiket
 * ogrenciyi sahte bir giris sayfasina gonderebilirdi.
 */
class ScannerPageTest extends TestCase
{
    use RefreshDatabase;

    private function sayfa(): string
    {
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();

        return $this->actingAs($ogrenci)->get(route('table.scanner'))->assertOk()->getContent();
    }

    public function test_the_manual_code_field_has_a_label_and_a_keyboard_fit_for_codes(): void
    {
        $html = $this->sayfa();

        $this->assertMatchesRegularExpression('/<label[^>]+for="js-masa-kodu"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="js-masa-kodu"[^>]+autocorrect="off"[^>]+spellcheck="false"[^>]+enterkeyhint="go"/s', $html);
    }

    /** Durum mesaji ekran okuyucuya okunur: bolge bastan sayfada, gizli degil. */
    public function test_the_status_message_is_announced(): void
    {
        $this->assertMatchesRegularExpression('/<div id="js-scanner-durum" role="status"[^>]*>/', $this->sayfa());
        $this->assertDoesNotMatchRegularExpression('/id="js-scanner-durum"[^>]*hidden/', $this->sayfa());
    }

    /**
     * Yedek cozucu yalnizca BarcodeDetector yoksa, sabit surum ve butunluk
     * ozetiyle yuklenir; sayfada sabit bir <script src> olarak durmaz.
     */
    public function test_the_fallback_decoder_is_loaded_lazily_with_integrity(): void
    {
        $html = $this->sayfa();

        $this->assertStringContainsString("'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js'", $html);
        $this->assertStringContainsString("'sha384-b5Ya4Bq3qCyz39m2ISh+4DxjAIljdeFwK/BsXLuj9gugaNwAcj/ia15fxNZL9Nlx'", $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src="https:\/\/cdn\.jsdelivr\.net\/npm\/jsqr/', $html);
    }

    /** @return array<string,array{0:string,1:?string}> okunan deger, gidilecek adres */
    public static function qrDegerleri(): array
    {
        return [
            'masa adresi' => ['https://kafe.test/masa/MASA-AB12CD34', 'https://kafe.test/masa/MASA-AB12CD34'],
            'baska site' => ['https://kafe-giris.example/masa/MASA-AB12CD34', null],
            'ayni site, baska sayfa' => ['https://kafe.test/login', null],
            'masa altinda baska yol' => ['https://kafe.test/masa/MASA-AB12CD34/basla', null],
            'javascript adresi' => ['javascript:alert(1)', null],
            'duz metin' => ['MASA-AB12CD34', null],
            'http ile ayni ana bilgisayar' => ['http://kafe.test/masa/MASA-AB12CD34', null],
        ];
    }

    /** Yalnizca AYNI kokenli /masa/{kod} adresine gidilir. */
    #[DataProvider('qrDegerleri')]
    public function test_only_a_same_origin_table_address_is_followed(string $okunan, ?string $beklenen): void
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node yok: okuyucunun adres kurali JS, node ile sinaniyor.');
        }

        preg_match('~// masaAdresi:bas(.*?)// masaAdresi:son~s', $this->sayfa(), $eslesme);
        $this->assertNotEmpty($eslesme, 'masaAdresi fonksiyonu sayfada bulunamadi');

        $betik = $eslesme[1] . "\nprocess.stdout.write(JSON.stringify(masaAdresi(" . json_encode($okunan) . ", 'https://kafe.test')));";
        $surec = new Process([$node, '-e', $betik]);
        $surec->mustRun();

        $this->assertSame($beklenen, json_decode($surec->getOutput(), true));
    }
}
