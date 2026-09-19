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
        // Kaynak belge (GUNCELLEMELER.md §1) "Tailwind 4 + Vite" diyor ama
        // gercekte tek bir tailwind satiri yok. Bu test yanlis varsayimla
        // @apply yazilmasini engeller.
        $this->assertStringNotContainsString('@apply', $this->stylesheet());
        $this->assertStringNotContainsString('tailwind', strtolower($this->stylesheet()));
    }
}
