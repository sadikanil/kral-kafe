<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Proje Tailwind KULLANMIYOR - stiller elle yazilmis public/css/app.css'te.
 * (resources/css/app.css derlenmiyor ve hicbir sayfaya ulasmiyor; layout'lar
 * asset('css/app.css') ile public altindakini yukluyor.)
 *
 * Bu yuzden bir sablonda uydurulan sinif adi sessizce hicbir sey yapmaz -
 * sayfa bicimsiz cikar ama hata vermez. Bu test, yeni ekranlarin ihtiyac
 * duydugu siniflarin gercekten tanimli oldugunu garanti eder.
 */
class StylesheetTest extends TestCase
{
    private function stylesheet(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    private function assertClassDefined(string $class): void
    {
        $this->assertMatchesRegularExpression(
            '/^\.' . preg_quote($class, '/') . '[\s,{:]/m',
            $this->stylesheet(),
            "public/css/app.css icinde .{$class} tanimli degil"
        );
    }

    public function test_progress_components_exist_for_study_goals(): void
    {
        foreach (['progress', 'progress-bar', 'progress-label'] as $class) {
            $this->assertClassDefined($class);
        }
    }

    public function test_live_session_components_exist(): void
    {
        foreach (['session-timer', 'session-card', 'live-dot'] as $class) {
            $this->assertClassDefined($class);
        }
    }

    public function test_an_empty_state_component_exists(): void
    {
        foreach (['empty-state', 'empty-state-icon', 'empty-state-title'] as $class) {
            $this->assertClassDefined($class);
        }
    }

    public function test_the_project_does_not_pull_in_tailwind(): void
    {
        // Proje bir donem "Tailwind 4 + Vite" diye belgelenmisti ama gercekte
        // tek bir tailwind satiri yok (bkz. README.md SS10.5). Bu test
        // yanlis varsayimla @apply yazilmasini engeller.
        $this->assertStringNotContainsString('@apply', $this->stylesheet());
        $this->assertStringNotContainsString('tailwind', strtolower($this->stylesheet()));
    }
    /**
     * Blade'lerde gecen her sinif gercekten tanimli olmali.
     *
     * Proje Tailwind ya da Bootstrap KULLANMIYOR; stiller public/css/app.css'te
     * elle yazili. Aliskanlikla yazilan bir Bootstrap adi (text-end, ms-2,
     * float-end) hata vermez - sessizce hicbir sey yapar ve ekran biraz bozuk
     * kalir. Bu test onlari yakalar.
     *
     * Tanimlar iki yerden toplanir: app.css ve blade'lerin kendi <style>
     * bloklari (auth duzeni ve QR yazdirma sayfasi stillerini orada tutuyor).
     */
    public function test_every_class_used_in_a_view_is_actually_defined(): void
    {
        $tanimli = $this->tanimliSiniflar();
        $eksik = [];

        foreach ($this->bladeDosyalari() as $dosya) {
            $icerik = (string) file_get_contents($dosya);

            if (! preg_match_all('/class="([^"]*)"/', $icerik, $eslesme)) {
                continue;
            }

            foreach ($eslesme[1] as $deger) {
                // Blade ifadeleri calisma aninda cozulur; statik olarak bakilamaz.
                $deger = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $deger);

                foreach (preg_split('/\s+/', trim($deger)) as $sinif) {
                    if ($sinif === '' || str_contains($sinif, '@') || str_contains($sinif, '$')) {
                        continue;
                    }
                    // js- oneki JavaScript kancasi demek, stil sinifi degil.
                    if (str_starts_with($sinif, 'js-')) {
                        continue;
                    }
                    // "badge-" gibi yarim kalan parcalar {{ }} temizliginden arta kalir.
                    if (str_ends_with($sinif, '-') || ! preg_match('/^[a-z][a-z0-9_-]*$/i', $sinif)) {
                        continue;
                    }
                    if (! isset($tanimli[$sinif])) {
                        $eksik[] = str_replace(base_path() . '/', '', $dosya) . ' -> .' . $sinif;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($eksik)), 'Tanimsiz CSS sinifi');
    }

    /**
     * Faz 3'te renk ve olcu adlari degisti (--gray-500 yerine --label-2 gibi).
     * Tanimsiz bir var() hata vermez: satir ici stil sessizce rengini ya da
     * boslugunu kaybeder. Gorunumlerde ve app.css'te kullanilan her ozel
     * ozellik app.css'te tanimli olmali (yedek degerli var(--x, ...) haric).
     */
    public function test_every_css_variable_used_is_defined(): void
    {
        $css = $this->stylesheet();
        preg_match_all('/(--[a-z0-9-]+)\s*:/', $css, $tanim);
        $tanimli = array_fill_keys($tanim[1], true);
        $eksik = [];

        $kaynaklar = ['public/css/app.css' => $css];
        foreach ($this->bladeDosyalari() as $dosya) {
            $kaynaklar[str_replace(base_path() . '/', '', $dosya)] = (string) file_get_contents($dosya);
        }

        foreach ($kaynaklar as $ad => $icerik) {
            preg_match_all('/var\((--[a-z0-9-]+)\s*\)/', $icerik, $m);
            foreach ($m[1] as $degisken) {
                if (! isset($tanimli[$degisken])) {
                    $eksik[] = "{$ad} -> {$degisken}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($eksik)), 'Tanimsiz CSS degiskeni');
    }

    /** @return array<string,true> */
    private function tanimliSiniflar(): array
    {
        $kaynak = (string) file_get_contents(public_path('css/app.css'));

        foreach ($this->bladeDosyalari() as $dosya) {
            $icerik = (string) file_get_contents($dosya);
            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $icerik, $eslesme)) {
                $kaynak .= implode('
', $eslesme[1]);
            }
        }

        preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $kaynak, $eslesme);

        return array_fill_keys($eslesme[1], true);
    }

    /** @return array<int,string> */
    private function bladeDosyalari(): array
    {
        $bulunan = [];
        $gezgin = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($gezgin as $dosya) {
            if ($dosya->isFile() && str_ends_with($dosya->getFilename(), '.blade.php')) {
                $bulunan[] = $dosya->getPathname();
            }
        }

        return $bulunan;
    }}
