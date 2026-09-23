<?php

namespace Tests\Feature;

use App\Support\Asset;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Cizgi simgeler (Faz 3): public/img/simgeler.svg sprite'i ve <x-icon>.
 *
 * Sprite'ta olmayan bir ad hata vermez; simge sessizce bos kalir. Bu testler
 * gorunumlerde yazilan her adin sprite'ta gercekten tanimli oldugunu
 * denetler (StylesheetTest'in siniflar icin yaptigi gibi).
 */
class IconTest extends TestCase
{
    /** @return array<string,true> */
    private function spriteAdlari(): array
    {
        preg_match_all('/<symbol id="([a-z0-9-]+)"/', (string) file_get_contents(public_path('img/simgeler.svg')), $m);

        return array_fill_keys($m[1], true);
    }

    public function test_the_icon_component_points_into_the_versioned_sprite(): void
    {
        $html = Blade::render('<x-icon name="house" />');

        $this->assertStringContainsString('<use href="' . Asset::url('img/simgeler.svg') . '#house"', $html);
        // Simge susar: adi yanindaki metin ya da dugmenin aria-label'i verir.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('focusable="false"', $html);
    }

    public function test_extra_classes_join_the_icon_class(): void
    {
        $this->assertStringContainsString('class="icon icon-lg"', Blade::render('<x-icon name="x" class="icon-lg" />'));
    }

    public function test_every_icon_named_in_a_view_exists_in_the_sprite(): void
    {
        $sprite = $this->spriteAdlari();
        $eksik = [];

        $gezgin = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($gezgin as $dosya) {
            if (! str_ends_with($dosya->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('/<x-icon\s+name="([a-z0-9-]+)"/', (string) file_get_contents($dosya), $m);
            foreach ($m[1] as $ad) {
                if (! isset($sprite[$ad])) {
                    $eksik[] = str_replace(base_path() . '/', '', $dosya->getPathname()) . ' -> ' . $ad;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($eksik)), 'Sprite\'ta olmayan simge');
    }
}
