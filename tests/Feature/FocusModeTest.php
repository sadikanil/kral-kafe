<?php

namespace Tests\Feature;

use App\Enums\PauseKind;
use App\Models\Package;
use App\Models\SessionPause;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Odak modu (Faz 4). Web uygulamasi baska uygulamalari engelleyemez; odak
 * modunda sayfadan ayrilmak sayilir ve ogrenci ile koc gorur. Sure DUSULMEZ,
 * yalnizca gosterilir (sahibin karari): cezalandirmak degil, farkindalik.
 */
class FocusModeTest extends TestCase
{
    use RefreshDatabase;

    private User $ogrenci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-16 14:00', config('kafe.timezone')));
        $this->ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();
    }

    private function oturum(int $dakikaOnce = 60): StudySession
    {
        return StudySession::create([
            'student_id' => $this->ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 1'])->id,
            'started_at' => now()->subMinutes($dakikaOnce),
        ]);
    }

    private function ayrildi(int $saniye)
    {
        return $this->actingAs($this->ogrenci)->postJson(route('session.focus.away'), ['saniye' => $saniye]);
    }

    public function test_leaving_the_app_in_focus_mode_is_counted_on_the_open_session(): void
    {
        $oturum = $this->oturum();

        $this->ayrildi(40)->assertOk()->assertJson(['sayi' => 1, 'saniye' => 40]);
        $this->ayrildi(20)->assertOk()->assertJson(['sayi' => 2, 'saniye' => 60]);

        $this->assertSame([2, 60], [$oturum->fresh()->focus_away_count, $oturum->fresh()->focus_away_seconds]);
    }

    /** Bildirime bir an bakmak ya da ekranin dönmesi cikis sayilmaz. */
    public function test_a_glance_under_three_seconds_is_not_counted(): void
    {
        $oturum = $this->oturum();

        $this->ayrildi(2)->assertUnprocessable();

        $this->assertSame(0, $oturum->fresh()->focus_away_count);
    }

    /** Telefon uykudan donunce tarayici uzun bir sure bildirebilir. */
    public function test_one_absence_never_exceeds_the_session_itself(): void
    {
        $oturum = $this->oturum(dakikaOnce: 10);

        $this->ayrildi(3600)->assertOk()->assertJson(['saniye' => 600]);

        $this->assertSame(600, $oturum->fresh()->focus_away_seconds);
    }

    /** Molada telefona bakmak serbest. */
    public function test_nothing_is_counted_during_a_break(): void
    {
        $oturum = $this->oturum();
        SessionPause::create(['study_session_id' => $oturum->id, 'kind' => PauseKind::Break->value, 'started_at' => now()->subMinutes(5)]);

        $this->ayrildi(120)->assertOk()->assertJson(['sayi' => 0, 'saniye' => 0]);

        $this->assertSame(0, $oturum->fresh()->focus_away_count);
    }

    public function test_without_an_open_session_nothing_is_recorded(): void
    {
        $this->ayrildi(30)->assertNotFound();
    }

    public function test_a_guest_cannot_report(): void
    {
        $this->postJson(route('session.focus.away'), ['saniye' => 30])->assertUnauthorized();
    }

    /** Arttirma tek satirda: baska ogrencinin oturumu etkilenmez. */
    public function test_only_the_students_own_session_changes(): void
    {
        $this->oturum();
        $baskasi = StudySession::create([
            'student_id' => User::factory()->student()->create()->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 2'])->id,
            'started_at' => now()->subHour(),
        ]);

        $this->ayrildi(30)->assertOk();

        $this->assertSame([0, 0], [$baskasi->fresh()->focus_away_count, $baskasi->fresh()->focus_away_seconds]);
    }
}
