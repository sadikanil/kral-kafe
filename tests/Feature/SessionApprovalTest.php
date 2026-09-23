<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Services\StudyStats;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 9 - Oturum onay akisi.
 *
 * Biten oturum dogrudan gorunur olmuyor: yoneticinin onay kuyruguna dusuyor.
 * Onaylanana kadar veli goremez ve hicbir istatistige girmez; ogrenci kendi
 * ham suresini "onay bekliyor" etiketiyle gorur.
 */
class SessionApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Kafe 09:00-21:00 arasi oturum acar (QA hata 7); saat sabit olmazsa
        // takim gece calistiginda her baslatma reddedilir. Ay ortasi: "3 gun
        // sonra" gibi kurulumlar ay degistirmesin.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-16 14:00', config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function masa(string $ad = 'Masa 1'): StudyTable
    {
        return StudyTable::create(['name' => $ad]);
    }

    /**
     * Bitmis bir oturum kurar. Onay durumuna hic dokunmaz - varsayilani
     * sinamak testin kendi isi.
     */
    private function bitmisOturum(User $ogrenci, int $dakika = 120): StudySession
    {
        $baslangic = now()->subMinutes($dakika);

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $this->masa()->id,
            'started_at' => $baslangic,
            'ended_at' => $baslangic->copy()->addMinutes($dakika),
            'duration_minutes' => $dakika,
            'end_reason' => SessionEndReason::Manual->value,
        ]);
    }

    public function test_a_finished_session_waits_for_approval(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->assertSame(ApprovalStatus::Pending, $oturum->approval_status);
    }

    public function test_approving_records_who_decided_and_when(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->assertTrue($oturum->approve($yonetici));

        $this->assertSame(ApprovalStatus::Approved, $oturum->approval_status);
        $this->assertSame($yonetici->id, $oturum->reviewed_by);
        $this->assertNotNull($oturum->reviewed_at);
    }

    public function test_a_rejected_session_keeps_its_record_and_shows_the_reason(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->assertTrue($oturum->reject($yonetici, 'Masada değildin, kamerada yoksun.'));

        $this->assertSame(ApprovalStatus::Rejected, $oturum->approval_status);
        $this->assertSame('Masada değildin, kamerada yoksun.', $oturum->rejection_reason);
        $this->assertDatabaseHas('study_sessions', ['id' => $oturum->id]);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->assertFalse($oturum->reject($yonetici, '   '));

        $this->assertSame(ApprovalStatus::Pending, $oturum->approval_status);
    }

    public function test_an_open_session_cannot_be_approved(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $ogrenci = $this->ogrenci();

        $acik = StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $this->masa()->id,
            'started_at' => now()->subHour(),
        ]);

        $this->assertFalse($acik->approve($yonetici));

        $this->assertSame(ApprovalStatus::Pending, $acik->approval_status);
    }

    public function test_a_pending_session_does_not_count_toward_study_time(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120);

        $this->assertSame(0, app(StudyStats::class)->todayMinutes($ogrenci));
    }

    public function test_an_approved_session_counts(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120)->approve($yonetici);

        $this->assertSame(120, app(StudyStats::class)->todayMinutes($ogrenci));
    }

    public function test_a_rejected_session_does_not_count(): void
    {
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120)->reject($yonetici, 'Masada değildin.');

        $this->assertSame(0, app(StudyStats::class)->todayMinutes($ogrenci));
    }

    // --- Yonetici onay kuyrugu (canli ekran) --------------------------------

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    public function test_the_live_screen_lists_sessions_awaiting_approval(): void
    {
        $ogrenci = User::factory()->create([
            'name' => 'Onay Bekleyen Öğrenci',
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
        $this->bitmisOturum($ogrenci, 120);

        $this->actingAs($this->yonetici())->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Onay Bekleyen Öğrenci');
    }

    public function test_an_approved_session_leaves_the_queue(): void
    {
        $ogrenci = User::factory()->create([
            'name' => 'Onaylanmış Öğrenci',
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
        $this->bitmisOturum($ogrenci, 120)->approve($this->yonetici());

        $this->actingAs($this->yonetici())->get(route('admin.live'))
            ->assertOk()
            ->assertDontSee('Onaylanmış Öğrenci');
    }

    public function test_an_admin_approves_from_the_queue(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici())
            ->post(route('admin.sessions.approve', $oturum))
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $oturum->fresh()->approval_status);
    }

    public function test_a_rejection_from_the_queue_needs_a_reason(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici())
            ->post(route('admin.sessions.reject', $oturum), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ApprovalStatus::Pending, $oturum->fresh()->approval_status);
    }

    public function test_a_rejection_from_the_queue_stores_the_reason(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici())
            ->post(route('admin.sessions.reject', $oturum), ['reason' => 'Masada yoktun.'])
            ->assertRedirect();

        $this->assertSame('Masada yoktun.', $oturum->fresh()->rejection_reason);
    }

    public function test_a_student_cannot_approve_a_session(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->ogrenci())
            ->post(route('admin.sessions.approve', $oturum))
            ->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $oturum->fresh()->approval_status);
    }

    /**
     * Gunde yirmi oturumu tek tek onaylamak, ozelligin kullanilmamasi demek.
     */
    public function test_the_whole_queue_can_be_approved_at_once(): void
    {
        $bir = $this->bitmisOturum($this->ogrenci());
        $iki = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici())
            ->post(route('admin.sessions.approve-many'), ['ids' => [$bir->id, $iki->id]])
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $bir->fresh()->approval_status);
        $this->assertSame(ApprovalStatus::Approved, $iki->fresh()->approval_status);
    }

    // --- Ogrencinin gordugu ------------------------------------------------

    /**
     * Ogrenci kendi ham suresini gorur.
     *
     * Gormezse "iki saat calistim ama panelde sifir yaziyor" durumu olusur ve
     * ogrenci sistemin kendisine guvenmeyi birakir. Sure gorunur, ama "onay
     * bekliyor" etiketiyle: henuz sayilmadigi da ayni ekranda yaziyor.
     */
    public function test_a_student_sees_their_own_session_awaiting_approval(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Onay bekliyor');
    }

    public function test_a_student_sees_why_a_session_was_rejected(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120)->reject($this->yonetici(), 'Masada değildin.');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Masada değildin.');
    }

    public function test_an_approved_session_is_not_listed_as_waiting(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, 120)->approve($this->yonetici());

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('Onay bekliyor');
    }
}
