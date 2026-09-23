<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Konum istegi "Calismaya Basla"yla yarismasin (QA a11y A21).
 *
 * Konum sayfa acilinca isteniyor; izin verildikten sonra hassas konum
 * saniyeler surebiliyor. Once basan ogrenci bos koordinat gonderiyor ve
 * yonetici izin verilmis olsa da "Konum yok" goruyordu. Gonderim, suren bir
 * istek varsa en fazla 3 sn bekler; izin yoksa akis degismez.
 */
class ScanLocationWaitTest extends TestCase
{
    use RefreshDatabase;

    private function betik(): string
    {
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $html = $this->actingAs($ogrenci)->get(route('table.scan', $masa->qr_code))->assertOk()->getContent();
        $this->assertStringContainsString('class="js-konumlu-form"', $html);
        preg_match('~// konum:bas(.*?)// konum:son~s', $html, $eslesme);
        $this->assertNotEmpty($eslesme, 'konum betigi sayfada bulunamadi');

        return $eslesme[1];
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> senaryo, beklenen */
    public static function senaryolar(): array
    {
        return [
            'konumdan once basildi, konum geldi' => [
                'const engellendi = gonder(); konumGeldi(); sonuc.engellendi = engellendi;',
                ['engellendi' => true, 'gonderim' => 1, 'enlem' => '41.0000000', 'mesgul' => 'true', 'kapali' => true],
            ],
            'konum hic gelmedi, 3 sn sonra gonderildi' => [
                'sonuc.engellendi = gonder(); sonuc.bekleme = zamanlayicilar[0].ms; zamanlayicilar[0].f(); zamanlayicilar[0].f();',
                ['engellendi' => true, 'gonderim' => 1, 'enlem' => '', 'mesgul' => 'true', 'kapali' => true, 'bekleme' => 3000],
            ],
            'konum zaten geldi, hemen gonderilir' => [
                'konumGeldi(); sonuc.engellendi = gonder();',
                ['engellendi' => false, 'gonderim' => 0, 'enlem' => '41.0000000', 'mesgul' => null, 'kapali' => false],
            ],
            'izin reddedildi, hemen gonderilir' => [
                'konumReddedildi(); sonuc.engellendi = gonder();',
                ['engellendi' => false, 'gonderim' => 0, 'enlem' => '', 'mesgul' => null, 'kapali' => false],
            ],
        ];
    }

    #[DataProvider('senaryolar')]
    public function test_the_start_waits_briefly_for_a_pending_location(string $senaryo, array $beklenen): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node yok: konum bekleme betigi JS, node ile sinaniyor.');
        }

        // Tarayici yerine en kucuk sahte ortam: tek form, tek dugme, sahte saat.
        $ortam = <<<'JS'
            const alan = s => ({ secici: s, value: '' });
            const alanlar = { '.js-konum-enlem': [alan('e')], '.js-konum-boylam': [alan('b')], '.js-konum-dogruluk': [alan('d')] };
            const dugme = { disabled: false, textContent: 'Çalışmaya Başla', nitelik: {}, setAttribute(k, v) { this.nitelik[k] = v; } };
            let dinleyici = null, gonderim = 0, konumOk = null, konumHata = null;
            const form = { dataset: {}, addEventListener(t, f) { dinleyici = f; }, querySelector() { return dugme; }, submit() { gonderim++; } };
            const zamanlayicilar = [];
            global.setTimeout = (f, ms) => { zamanlayicilar.push({ f, ms }); return zamanlayicilar.length; };
            // Node 22'de navigator salt okunur bir global; atama sessizce yok sayilir.
            Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { geolocation: { getCurrentPosition(ok, hata) { konumOk = ok; konumHata = hata; } } } });
            global.document = { querySelectorAll: s => s === '.js-konumlu-form' ? [form] : (alanlar[s] || []) };
            const konumGeldi = () => konumOk({ coords: { latitude: 41, longitude: 29, accuracy: 12.4 } });
            const konumReddedildi = () => konumHata({});
            // Olay: engellendiyse true doner (tarayici gonderimi durdurdu).
            const gonder = () => { let dur = false; dinleyici({ preventDefault() { dur = true; } }); return dur; };
            const sonuc = {};
        JS;

        $sonuc = <<<'JS'
            Object.assign(sonuc, { gonderim, enlem: alanlar['.js-konum-enlem'][0].value, mesgul: dugme.nitelik['aria-busy'] ?? null, kapali: dugme.disabled });
            process.stdout.write(JSON.stringify(sonuc));
        JS;

        $surec = new Process([$node, '-e', $ortam . $this->betik() . "\n" . $senaryo . "\n" . $sonuc]);
        $surec->mustRun();

        $gercek = json_decode($surec->getOutput(), true);
        ksort($gercek);
        ksort($beklenen);
        $this->assertSame($beklenen, $gercek);
    }
}
