<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\Leaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vercel onizleme veritabani (9 Kasim 2026). kafe:onizleme-hazirla derleme
 * sirasinda calisir; yalnizca VERCEL_ENV=preview + KAFE_ONIZLEME_DB=1 iken
 * ve canli projeye bagli DEGILKEN yazar.
 */
class PreviewDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function onizleme(string $ortam = 'preview', bool $acik = true): void
    {
        config(['kafe.onizleme.vercel_ortami' => $ortam, 'kafe.onizleme.acik' => $acik]);
    }

    public function test_it_never_touches_the_database_outside_a_preview_build(): void
    {
        foreach (['production', '', 'development'] as $ortam) {
            $this->onizleme($ortam);
            $this->artisan('kafe:onizleme-hazirla')->assertSuccessful();
        }

        $this->assertSame(0, User::count());
    }

    public function test_it_needs_the_preview_only_switch(): void
    {
        $this->onizleme('preview', false);

        $this->artisan('kafe:onizleme-hazirla')
            ->expectsOutputToContain('KAFE_ONIZLEME_DB tanımlı değil')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_and_fails_the_build_when_pointed_at_the_live_project(): void
    {
        $this->onizleme();
        $ad = config('database.default');
        config(["database.connections.{$ad}.username" => 'postgres.hxlklrwbeeddbajiectt']);

        $this->artisan('kafe:onizleme-hazirla')
            ->expectsOutputToContain('CANLI veritabanına bağlı')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_seeds_an_empty_preview_database_once_with_passwordless_demo_people(): void
    {
        // Pazartesi sabahi: gecmis gunlerin hepsi gecen haftada; tablo yine dolu olmali.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 11:00', config('kafe.timezone')));
        $this->onizleme();

        $this->artisan('kafe:onizleme-hazirla')
            ->expectsOutputToContain('demo verisi yazıldı')
            ->assertSuccessful();

        $this->assertSame(1, User::where('role', Role::Admin->value)->where('email', 'yonetici@onizleme.test')->count());
        $this->assertSame(8, User::where('role', Role::Student->value)->count());
        $this->assertSame(1, User::where('role', Role::Staff->value)->count());
        $this->assertSame(0, User::whereNotNull('password')->count(), 'herkes ilk giriste kendi sifresini belirler');
        $this->assertSame(8, StudyTable::count());
        $this->assertSame(1, StudySession::open()->count());
        $this->assertSame(1, StudySession::unseenAnomalies()->count());

        $p = Leaderboard::pencere('hafta', null);
        $this->assertGreaterThanOrEqual(3, app(Leaderboard::class)->satirlar($p['from'], $p['to'])->count());

        // Ikinci derleme: veri yeniden yazilmaz.
        $this->artisan('kafe:onizleme-hazirla')
            ->expectsOutputToContain('demo verisi yeniden yazılmadı')
            ->assertSuccessful();
        $this->assertSame(8, User::where('role', Role::Student->value)->count());
    }

    public function test_the_first_login_sets_the_demo_admin_password(): void
    {
        $this->onizleme();
        $this->artisan('kafe:onizleme-hazirla')->assertSuccessful();

        $this->post(route('login'), ['kimlik' => 'yonetici@onizleme.test'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('giris_adimi', 'belirle');
    }

    public function test_the_vercel_build_runs_it_last(): void
    {
        $betik = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['vercel'];

        $this->assertSame('@php artisan kafe:onizleme-hazirla', end($betik));
    }
}
