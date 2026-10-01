<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ana ekran uygulamasi (Faz 4). Ogrenci siteyi telefonunda uygulama gibi
 * acar: adres cubugu yok, simge ana ekranda, kafe Wi-Fi'i koptugunda bos
 * ekran yerine kisa bir "baglanti yok" sayfasi.
 *
 * Service worker yalnizca surumlu statik dosyalari tutar; oturumlu sayfalar
 * hic onbellege girmez (baska ogrencinin paneli ya da eski bakiye
 * gorunmesin).
 */
class HomeScreenAppTest extends TestCase
{
    use RefreshDatabase;

    private function manifest(): array
    {
        return json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_manifest_describes_a_standalone_turkish_app(): void
    {
        $m = $this->manifest();

        $this->assertSame('Kral Kafe', $m['name']);
        $this->assertSame('Kral Kafe', $m['short_name']);
        $this->assertSame('/', $m['start_url']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('tr', $m['lang']);
    }

    /** Android 192 ve 512 ister; maskelenebilir simge kenarlari kesilmeden sigar. */
    public function test_every_manifest_icon_exists_with_its_declared_size(): void
    {
        $boyutlar = [];

        foreach ($this->manifest()['icons'] as $simge) {
            $dosya = public_path(ltrim($simge['src'], '/'));
            $this->assertFileExists($dosya);
            [$en, $boy] = getimagesize($dosya);
            $this->assertSame($simge['sizes'], "{$en}x{$boy}", $simge['src']);
            $boyutlar[] = $simge['sizes'] . ' ' . ($simge['purpose'] ?? 'any');
        }

        $this->assertContains('192x192 any', $boyutlar);
        $this->assertContains('512x512 any', $boyutlar);
        $this->assertContains('512x512 maskable', $boyutlar);
    }

    public function test_the_apple_touch_icon_is_180px(): void
    {
        [$en, $boy] = getimagesize(public_path('img/apple-touch-icon.png'));

        $this->assertSame([180, 180], [$en, $boy]);
    }

    public function test_both_layouts_link_the_app_metadata(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        foreach ([$this->get(route('login')), $this->actingAs($ogrenci)->get(route('user.dashboard'))] as $yanit) {
            $yanit->assertOk()
                ->assertSee('<link rel="manifest" href="' . asset('manifest.webmanifest') . '">', false)
                ->assertSee('<link rel="apple-touch-icon" href="' . asset('img/apple-touch-icon.png') . '">', false)
                ->assertSee('<meta name="apple-mobile-web-app-title" content="Kral Kafe">', false);
        }
    }

    public function test_the_offline_page_stands_alone(): void
    {
        $html = (string) file_get_contents(public_path('cevrimdisi.html'));

        $this->assertStringContainsString('<html lang="tr">', $html);
        $this->assertStringContainsString('Bağlantı yok', $html);
        // Cevrimdisiyken baska dosya gelmez: stil sayfanin icinde.
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }
}
