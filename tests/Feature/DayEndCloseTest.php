<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\PauseKind;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gece 00:00 kurali (1 Ekim 2026).
 *
 * Bazi ogrenciler oturumu duraklatip kafeden cikiyor. Oturum gun sonunda
 * kapanir, duraklama suresi calisma sayilmaz; kapanis yoneticinin onay
 * kuyruguna ETIKETLI duser ve "Hepsini onayla"ya girmez.
 */
class DayEndCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saat(string $yerel): void
    {
        Carbon::setTestNow(Carbon::parse($yerel, config('kafe.timezone')));
    }

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    /** 14:00'te baslar, 17:05'te duraklatip gider. */
    private function duraklatipGiden(): StudySession
    {
        $this->saat('2026-09-23 14:00');
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())
            ->create(['name' => 'Duraklatıp Giden']);
        $servis = app(StudySessionService::class);
        $oturum = $servis->start($ogrenci, StudyTable::create(['name' => 'Masa 1']));

        $this->saat('2026-09-23 17:05');
        $servis->pause($oturum, PauseKind::Pause);

        return $oturum;
    }

    private function elleBitmis(): StudySession
    {
        $this->saat('2026-09-24 08:00');
        $ogrenci = User::factory()->create(['role' => Role::Student->value, 'subscription_status' => 'active']);

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 2'])->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHour(),
            'duration_minutes' => 120,
            'end_reason' => SessionEndReason::Manual->value,
        ]);
    }

    public function test_the_nightly_job_closes_a_paused_session_at_midnight_without_counting_the_pause(): void
    {
        $oturum = $this->duraklatipGiden();
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->saat('2026-09-24 00:07');
        $this->withToken('gizli')->get(route('cron.nightly'))
            ->assertOk()->assertJson(['kapanan_oturum' => 1]);

        $oturum->refresh();
        $gece = Carbon::parse('2026-09-24 00:00', config('kafe.timezone'));
        $this->assertSame(SessionEndReason::DayEnd, $oturum->end_reason);
        $this->assertTrue($oturum->ended_at->equalTo($gece));
        $this->assertSame(185, $oturum->duration_minutes, 'Duraklama calisma sayildi');
        $this->assertTrue($oturum->pauses()->sole()->ended_at->equalTo($gece));
        $this->assertSame(ApprovalStatus::Pending, $oturum->approval_status);
        $this->assertSame('17:05', $oturum->pauseLeftOpen()->started_at->timezone(config('kafe.timezone'))->format('H:i'));
    }

    public function test_the_nightly_job_needs_the_secret(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->get(route('cron.nightly'))->assertForbidden();
        $this->withToken('yanlis')->get(route('cron.nightly'))->assertForbidden();
    }

    public function test_the_queue_tags_the_midnight_close_and_leaves_it_out_of_bulk_approval(): void
    {
        $gece = $this->duraklatipGiden();
        $elle = $this->elleBitmis();

        $yanit = $this->actingAs($this->yonetici())->get(route('admin.live'))->assertOk();
        $gece->refresh();

        $yanit->assertSee('Gece 00:00&#039;da kapandı', false)
            ->assertSee('Duraklatıp gitmiş (mola başı 17:05)')
            ->assertSee('toplu onaya girmez')
            ->assertSee('Diğerlerini onayla (1)')
            ->assertSee('name="ids[]" value="' . $elle->id . '"', false)
            ->assertDontSee('name="ids[]" value="' . $gece->id . '"', false);
    }

    public function test_bulk_approval_skips_sessions_the_system_closed(): void
    {
        $gece = $this->duraklatipGiden();
        $elle = $this->elleBitmis();
        $yonetici = $this->yonetici();
        $this->actingAs($yonetici)->get(route('admin.live'));

        $this->actingAs($yonetici)
            ->post(route('admin.sessions.approve-many'), ['ids' => [$gece->id, $elle->id]])
            ->assertSessionHas('success', '1 oturum onaylandı.');

        $this->assertSame(ApprovalStatus::Pending, $gece->fresh()->approval_status);
        $this->assertSame(ApprovalStatus::Approved, $elle->fresh()->approval_status);

        $this->actingAs($yonetici)->post(route('admin.sessions.approve', $gece));
        $this->assertSame(ApprovalStatus::Approved, $gece->fresh()->approval_status);
    }

    public function test_a_session_the_student_ended_while_paused_is_not_tagged(): void
    {
        $oturum = $this->duraklatipGiden();

        $this->saat('2026-09-23 17:30');
        app(StudySessionService::class)->endFor($oturum->student);

        $oturum->refresh();
        $this->assertSame(SessionEndReason::Manual, $oturum->end_reason);
        $this->assertNull($oturum->pauseLeftOpen());
    }
}
