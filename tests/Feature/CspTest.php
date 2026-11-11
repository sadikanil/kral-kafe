<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Content-Security-Policy (7 Kasim 2026). Politika App\Support\Csp'de.
 *
 * Iki tur muhafiz: (1) gorunumler - her <script> @nonce tasir, hicbir
 * etiket satir ici onclick/onsubmit kullanmaz (CSP onlari calistirmaz, dugme
 * sessizce bozulur); (2) yanitlar - baslik gelir, nonce sayfadaki betiklerle
 * ayni ve her istekte yeni.
 */
class CspTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,string> yol => icerik */
    private function gorunumler(): array
    {
        $dosyalar = [];
        foreach (File::allFiles(resource_path('views')) as $dosya) {
            $dosyalar[$dosya->getRelativePathname()] = $dosya->getContents();
        }

        return $dosyalar;
    }

    public function test_every_script_tag_in_the_views_carries_the_nonce(): void
    {
        $eksik = [];
        foreach ($this->gorunumler() as $yol => $icerik) {
            if (preg_match_all('/<script(?=[\s>])[^>]*>/i', $icerik, $etiketler)) {
                foreach ($etiketler[0] as $etiket) {
                    if (! str_contains($etiket, '@nonce')) {
                        $eksik[] = "$yol: $etiket";
                    }
                }
            }
        }

        $this->assertSame([], $eksik, 'CSP nonce\'suz satir ici betigi calistirmaz; <script @nonce> yaz.');
    }

    public function test_no_view_uses_inline_event_handlers(): void
    {
        $bulunan = [];
        foreach ($this->gorunumler() as $yol => $icerik) {
            // Etiket icindeki on...= niteligi; betik govdesindeki "const onceki =" degil.
            if (preg_match_all('/<[a-z][^<>]*\son[a-z]+\s*=\s*["\']/i', $icerik, $eslesen)) {
                foreach ($eslesen[0] as $parca) {
                    $bulunan[] = "$yol: " . trim(substr($parca, 0, 90));
                }
            }
        }

        $this->assertSame([], $bulunan, 'Satir ici olay CSP ile calismaz: data-confirm, data-yazdir, data-yenile, data-otomatik-gonder kullan (public/js/kabuk.js).');
    }

    public function test_no_view_loads_scripts_from_another_origin(): void
    {
        foreach ($this->gorunumler() as $yol => $icerik) {
            $this->assertDoesNotMatchRegularExpression('#<script[^>]+src=["\']https?://#i', $icerik, $yol);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $icerik, $yol);
        }
    }

    public function test_the_page_scripts_carry_the_nonce_from_the_header(): void
    {
        $yanit = $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.index'))->assertOk();

        $politika = $yanit->headers->get('Content-Security-Policy');
        $this->assertNotNull($politika);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $politika);
        preg_match("/'nonce-([^']+)'/", $politika, $m);
        $nonce = $m[1];

        $html = $yanit->getContent();
        preg_match_all('/<script(?=[\s>])[^>]*>/i', $html, $etiketler);
        $this->assertNotEmpty($etiketler[0]);
        foreach ($etiketler[0] as $etiket) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $etiket);
        }

        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'"] as $kural) {
            $this->assertStringContainsString($kural, $politika);
        }
        $this->assertStringNotContainsString('unsafe-eval', $politika);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*unsafe-inline/", $politika);
    }

    public function test_each_request_gets_a_fresh_nonce(): void
    {
        $bir = $this->get('/giris')->headers->get('Content-Security-Policy');
        $iki = $this->get('/giris')->headers->get('Content-Security-Policy');

        $this->assertNotNull($bir);
        $this->assertNotSame($bir, $iki);
    }

    public function test_report_mode_only_reports_and_off_sends_nothing(): void
    {
        config(['kafe.csp' => 'report']);
        $yanit = $this->get('/giris');
        $this->assertNull($yanit->headers->get('Content-Security-Policy'));
        $this->assertNotNull($yanit->headers->get('Content-Security-Policy-Report-Only'));

        config(['kafe.csp' => 'off']);
        $yanit = $this->get('/giris');
        $this->assertNull($yanit->headers->get('Content-Security-Policy'));
        $this->assertNull($yanit->headers->get('Content-Security-Policy-Report-Only'));

        // Bilinmeyen deger en guvenli moda duser.
        config(['kafe.csp' => 'yanlis']);
        $this->assertNotNull($this->get('/giris')->headers->get('Content-Security-Policy'));
    }

    public function test_the_scanner_decoder_and_vercel_scripts_are_same_origin(): void
    {
        $this->assertFileExists(public_path('js/vendor/jsQR.js'));
        $this->assertFileExists(public_path('js/vendor/jsQR.LICENSE.txt'));

        $analitik = File::get(resource_path('views/layouts/_analitik.blade.php'));
        $this->assertStringContainsString('src="/_vercel/insights/script.js"', $analitik);
        $this->assertStringContainsString('src="/_vercel/speed-insights/script.js"', $analitik);
    }
}
