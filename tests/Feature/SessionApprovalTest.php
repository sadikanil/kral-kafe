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
}
