<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\SessionCloser;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kafe kapaliyken calisma baslamaz (QA hata 7).
 *
 * 21:20'de baslayan oturumun kapanisi ertesi gunun 21:00'ine kayiyordu; 12
 * saat siniri onu ertesi sabah 09:20'de kapatiyordu. Ogrenci sabah gelince
 * dunku oturuma devam ediyor ve 09:20'de atiliyor, ya da baska masada 705
 * dakikalik "masa degistirdi" kaydi (anomali DEGIL) olusuyordu.
 */
class CafeHoursStartTest extends TestCase
{
    use RefreshDatabase;

    private function saat(string $yerel): void
    {
        $this->travelTo(Carbon::parse($yerel, config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function saatler(): array
    {
        return [
            'gece yarisindan sonra' => ['2026-09-29 00:30', false],
            'acilistan hemen once' => ['2026-09-29 08:59', false],
            'tam acilis' => ['2026-09-29 09:00', true],
            'kapanistan hemen once' => ['2026-09-29 20:59', true],
            'tam kapanis' => ['2026-09-29 21:00', false],
            'kapanistan sonra' => ['2026-09-29 21:20', false],
        ];
    }

    #[DataProvider('saatler')]
    public function test_the_cafe_is_open_only_between_opening_and_closing(string $yerel, bool $acik): void
    {
        $this->assertSame($acik, app(SessionCloser::class)->isOpenAt(Carbon::parse($yerel, config('kafe.timezone'))));
    }

    public function test_starting_after_closing_is_refused_with_the_opening_hours(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $this->saat('2026-09-29 21:20');

        $this->actingAs($this->ogrenci())
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('table.scan', $masa->qr_code))
            ->assertSessionHas('error', 'Kafe şu anda kapalı (09:00–21:00). Çalışma açılış saatinde başlatılabilir.');

        $this->assertSame(0, StudySession::count());
    }

    public function test_starting_during_opening_hours_still_works(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $this->saat('2026-09-29 09:00');

        $this->actingAs($this->ogrenci())
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('session.timer'));

        $this->assertSame(1, StudySession::open()->count());
    }
}
