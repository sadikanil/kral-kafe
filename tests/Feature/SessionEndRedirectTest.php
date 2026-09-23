<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bitir tek yonlendirme (QA perf P7).
 *
 * Sayactan bitirmek uc sirali gidis-donustu: POST -> geri (sayac) -> sayac
 * acik oturum bulamayip panele yonlendiriyordu. Turkiye'den her biri
 * 0,5-0,8 sn. Bitirdikten sonra gidilecek tek yer panel.
 */
class SessionEndRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
    }

    public function test_ending_from_the_timer_goes_straight_to_the_panel(): void
    {
        $ogrenci = $this->ogrenci();
        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 1'])->id,
            'started_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.end'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('success', 'Çalışma bitti. Süre: 45 dakika.');
    }

    public function test_ending_without_a_session_also_lands_on_the_panel(): void
    {
        $this->actingAs($this->ogrenci())->from(route('session.timer'))
            ->post(route('session.end'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error', 'Açık bir çalışma oturumunuz yok.');
    }
}
