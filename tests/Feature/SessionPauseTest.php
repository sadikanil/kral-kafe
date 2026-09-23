<?php

namespace Tests\Feature;

use App\Enums\PauseKind;
use App\Enums\SessionEndReason;
use App\Models\Package;
use App\Models\SessionPause;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 23: calisma sayaci. Ogrenci oturumu kendi istegiyle duraklatir,
 * 15 dk mola ya da 1 saat ogle arasi verir, sonra devam eder. Duraklamada
 * gecen sure CALISMA SAYILMAZ. Mola bitince sure kendiliginden akmaz;
 * ogrenci "Devam"a basar (karar, 23 Eyl).
 */
class SessionPauseTest extends TestCase
{
    use RefreshDatabase;

    private StudySessionService $servis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servis = app(StudySessionService::class);
        Carbon::setTestNow('2026-09-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier1())->create();
    }

    private function oturum(User $ogrenci): StudySession
    {
        return $this->servis->start($ogrenci, StudyTable::create(['name' => 'Masa 1']));
    }

    // --- Sure hesabi --------------------------------------------------------

    public function test_a_pause_stops_the_clock(): void
    {
        $oturum = $this->oturum($this->ogrenci());

        Carbon::setTestNow('2026-09-23 10:40:00');
        $this->servis->pause($oturum, PauseKind::Pause);

        Carbon::setTestNow('2026-09-23 11:00:00');
        $this->assertSame(40, $oturum->fresh()->minutesSoFar());
    }

    public function test_resuming_starts_the_clock_again(): void
    {
        $oturum = $this->oturum($this->ogrenci());

        Carbon::setTestNow('2026-09-23 10:40:00');
        $this->servis->pause($oturum, PauseKind::Break);
        Carbon::setTestNow('2026-09-23 10:55:00');
        $this->servis->resume($oturum);

        Carbon::setTestNow('2026-09-23 11:15:00');
        $this->assertSame(60, $oturum->fresh()->minutesSoFar());
    }

    public function test_several_pauses_add_up(): void
    {
        $oturum = $this->oturum($this->ogrenci());

        foreach ([['10:10', '10:20'], ['10:30', '10:45']] as [$bas, $bit]) {
            Carbon::setTestNow("2026-09-23 {$bas}:00");
            $this->servis->pause($oturum, PauseKind::Pause);
            Carbon::setTestNow("2026-09-23 {$bit}:00");
            $this->servis->resume($oturum);
        }

        Carbon::setTestNow('2026-09-23 11:00:00');
        $this->assertSame(35, $oturum->fresh()->minutesSoFar());
    }

    public function test_ending_while_paused_closes_the_pause_and_keeps_it_out(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci);

        Carbon::setTestNow('2026-09-23 12:00:00');
        $this->servis->pause($oturum, PauseKind::Lunch);
        Carbon::setTestNow('2026-09-23 12:30:00');
        $this->servis->endFor($ogrenci);

        $oturum->refresh();
        $this->assertSame(120, $oturum->duration_minutes);
        $this->assertNotNull(SessionPause::sole()->ended_at);
    }

    /** Ogle arasinda kafeden cikip unutan ogrencinin oturumu kapanista kapanir. */
    public function test_the_auto_close_also_closes_an_open_pause(): void
    {
        $oturum = $this->oturum($this->ogrenci());
        Carbon::setTestNow('2026-09-23 12:00:00');
        $this->servis->pause($oturum, PauseKind::Lunch);

        $oturum->closeOnce(Carbon::parse('2026-09-23 13:00:00'), SessionEndReason::AutoClosed);

        $this->assertSame(120, $oturum->fresh()->duration_minutes);
        $this->assertEquals(Carbon::parse('2026-09-23 13:00:00'), SessionPause::sole()->ended_at);
    }

    /** Kapali oturum kayitli sureyi verir; listeler her satir icin mola sorgulamasin. */
    public function test_a_closed_session_reports_its_stored_duration(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci);
        Carbon::setTestNow('2026-09-23 11:00:00');
        $this->servis->endFor($ogrenci);
        $oturum->refresh()->forceFill(['duration_minutes' => 55])->save();

        $this->assertSame(55, StudySession::find($oturum->id)->minutesSoFar());
    }

    // --- Kurallar ------------------------------------------------------------

    public function test_pausing_twice_keeps_one_pause(): void
    {
        $oturum = $this->oturum($this->ogrenci());

        $this->servis->pause($oturum, PauseKind::Pause);
        $this->servis->pause($oturum, PauseKind::Break);

        $this->assertSame(1, SessionPause::count());
    }

    public function test_resuming_without_a_pause_does_nothing(): void
    {
        $oturum = $this->oturum($this->ogrenci());

        $this->servis->resume($oturum);

        $this->assertSame(0, SessionPause::count());
    }

    public function test_breaks_carry_their_planned_length(): void
    {
        $this->assertSame(15, PauseKind::Break->plannedMinutes());
        $this->assertSame(60, PauseKind::Lunch->plannedMinutes());
        $this->assertNull(PauseKind::Pause->plannedMinutes());
    }

    public function test_a_break_knows_how_much_is_left(): void
    {
        $oturum = $this->oturum($this->ogrenci());
        $this->servis->pause($oturum, PauseKind::Break);

        Carbon::setTestNow('2026-09-23 10:10:00');

        $this->assertSame(300, $oturum->fresh()->openPause()->secondsLeft());
    }

    /** Hatirlaticilar araliksiz calismaya bakar: mola sayaci sifirlar. */
    public function test_continuous_work_restarts_after_a_pause(): void
    {
        $oturum = $this->oturum($this->ogrenci());
        Carbon::setTestNow('2026-09-23 10:50:00');
        $this->servis->pause($oturum, PauseKind::Break);
        Carbon::setTestNow('2026-09-23 11:05:00');
        $this->servis->resume($oturum);

        Carbon::setTestNow('2026-09-23 11:25:00');

        $this->assertSame(20 * 60, $oturum->fresh()->continuousSeconds());
    }

    // --- Sayfa ve uclar -----------------------------------------------------

    public function test_scanning_leads_to_the_timer(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($this->ogrenci())
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('session.timer'));
    }

    public function test_the_timer_shows_the_quick_actions(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci);

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('15 dk mola')
            ->assertSee('Öğle arası')
            ->assertSee('Duraklat');
    }

    /** Bitirince "Calisma bitti" mesaji ikinci yonlendirmede kaybolmamali. */
    public function test_the_timer_without_a_session_sends_to_the_panel(): void
    {
        $this->actingAs($this->ogrenci())->get(route('session.timer'))
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_the_student_pauses_and_resumes_over_http(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci);

        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'lunch'])
            ->assertRedirect(route('session.timer'));
        $this->assertSame(PauseKind::Lunch, $oturum->fresh()->openPause()->kind);

        $this->actingAs($ogrenci)->post(route('session.resume'))->assertRedirect(route('session.timer'));
        $this->assertNull($oturum->fresh()->openPause());
    }

    public function test_an_unknown_pause_kind_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci);

        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'tatil'])
            ->assertSessionHasErrors('tur');
    }

    public function test_the_live_screen_marks_a_paused_student(): void
    {
        $ogrenci = $this->ogrenci();
        $this->servis->pause($this->oturum($ogrenci), PauseKind::Lunch);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.live'))
            ->assertSee('Öğle arası');
    }
}
