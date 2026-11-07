<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * README SS4.2 teknik borcu (7 Kasim 2026): anomaliye "gordum", gorevliye
 * masa yonetimi, masaya bolum ve sabit uye.
 */
class TechDebtNovemberTest extends TestCase
{
    use RefreshDatabase;

    private function kisi(Role $rol, array $ek = []): User
    {
        return User::factory()->create(['role' => $rol->value, 'subscription_status' => 'active'] + $ek);
    }

    private function anomali(User $uye, int $gunOnce = 1): StudySession
    {
        $bitis = now()->subDays($gunOnce);

        return StudySession::create([
            'student_id' => $uye->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bitis->copy()->subHours(12),
            'ended_at' => $bitis,
            'duration_minutes' => 720,
            'end_reason' => SessionEndReason::OverLimit->value,
            'approval_status' => ApprovalStatus::Pending->value,
        ]);
    }

    // --- Anomaliye "gordum" -------------------------------------------------

    public function test_an_anomaly_stays_on_the_live_screen_until_someone_marks_it_seen(): void
    {
        $yonetici = $this->kisi(Role::Admin);
        // Eskiden 7 gunluk pencereden dusup kaybolurdu.
        $eski = $this->anomali($this->kisi(Role::Student, ['name' => 'Unutkan Uğur']), 10);

        $this->actingAs($yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Görülmemiş anomaliler (1)')
            ->assertSee('Unutkan Uğur');

        $this->actingAs($yonetici)->post(route('admin.sessions.anomaly-seen', $eski))->assertRedirect();

        $eski->refresh();
        $this->assertNotNull($eski->anomaly_seen_at);
        $this->assertSame($yonetici->id, $eski->anomaly_seen_by);
        // Isaret onayi ve sureyi degistirmez.
        $this->assertSame(ApprovalStatus::Pending, $eski->approval_status);
        $this->assertSame(720, $eski->duration_minutes);

        $this->actingAs($yonetici)->get(route('admin.live'))->assertDontSee('Görülmemiş anomaliler');
    }

    public function test_seen_all_marks_every_open_anomaly_and_keeps_the_first_marker(): void
    {
        $ilk = $this->kisi(Role::Admin);
        $ikinci = $this->kisi(Role::Admin);
        $a = $this->anomali($this->kisi(Role::Student));
        $b = $this->anomali($this->kisi(Role::Student));

        $this->actingAs($ilk)->post(route('admin.sessions.anomaly-seen', $a));
        $this->actingAs($ikinci)->post(route('admin.sessions.anomalies-seen'))->assertRedirect();

        $this->assertSame($ilk->id, $a->fresh()->anomaly_seen_by, 'ilk isaretleyen ezilmemeli');
        $this->assertSame($ikinci->id, $b->fresh()->anomaly_seen_by);
        $this->assertSame(0, StudySession::unseenAnomalies()->count());

        // Zaten gorulmus ya da anomali olmayan oturum: 404.
        $this->actingAs($ikinci)->post(route('admin.sessions.anomaly-seen', $a))->assertNotFound();
    }

    public function test_only_admins_can_mark_anomalies(): void
    {
        $oturum = $this->anomali($this->kisi(Role::Student));

        foreach ([Role::Staff, Role::Coach, Role::Student, Role::Parent] as $rol) {
            $this->actingAs($this->kisi($rol))->post(route('admin.sessions.anomaly-seen', $oturum))->assertForbidden();
        }
        $this->assertNull($oturum->fresh()->anomaly_seen_at);
    }

    public function test_the_migration_counted_old_anomalies_as_already_seen(): void
    {
        // Migration'in veri adimi: 7 gunden eskiler eski pencereden zaten dusmustu.
        $eski = $this->anomali($this->kisi(Role::Student), 9);
        $yeni = $this->anomali($this->kisi(Role::Student), 2);

        DB::table('study_sessions')
            ->where('end_reason', 'over_limit')->whereNull('anomaly_seen_at')
            ->where('ended_at', '<', now()->subDays(7))
            ->update(['anomaly_seen_at' => now()]);

        $this->assertNotNull($eski->fresh()->anomaly_seen_at);
        $this->assertNull($yeni->fresh()->anomaly_seen_at);
        $this->assertNull($eski->fresh()->anomaly_seen_by, 'bos = sistem');
    }

    // --- Gorevli masa yonetimi -----------------------------------------------

    public function test_staff_manage_tables_but_cannot_delete_them_or_open_other_admin_pages(): void
    {
        $gorevli = $this->kisi(Role::Staff);
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($gorevli)->get(route('admin.tables.index'))->assertOk()->assertSee('Masa 1');
        $this->actingAs($gorevli)->get(route('admin.tables.create'))->assertOk();
        $this->actingAs($gorevli)->post(route('admin.tables.store'), ['name' => 'Masa 2'])->assertRedirect(route('admin.tables.index'));
        $this->actingAs($gorevli)->get(route('admin.tables.edit', $masa))->assertOk();
        $this->actingAs($gorevli)->put(route('admin.tables.update', $masa), ['name' => 'Pencere', 'is_active' => '1'])->assertRedirect();
        $this->actingAs($gorevli)->post(route('admin.tables.toggle-status', $masa))->assertRedirect();
        $this->actingAs($gorevli)->get(route('admin.tables.qr', $masa))->assertOk();
        $this->actingAs($gorevli)->get(route('admin.tables.print-qr'))->assertOk();

        $this->assertSame('Pencere', $masa->fresh()->name);
        $this->assertTrue(StudyTable::where('name', 'Masa 2')->exists());

        $this->actingAs($gorevli)->delete(route('admin.tables.destroy', StudyTable::where('name', 'Masa 2')->first()))->assertForbidden();
        foreach (['admin.dashboard', 'admin.live', 'admin.users.index', 'admin.products.index', 'admin.bills.index'] as $rota) {
            $this->actingAs($gorevli)->get(route($rota))->assertForbidden();
        }
    }

    public function test_staff_see_tables_in_their_menu_and_land_there(): void
    {
        $gorevli = $this->kisi(Role::Staff);

        $this->actingAs($gorevli)->get('/')->assertRedirect(route('admin.tables.index'));
        $this->actingAs($gorevli)->get(route('admin.tables.index'))
            ->assertSee('href="' . route('admin.tables.index') . '"', false)
            ->assertDontSee('href="' . route('admin.users.index') . '"', false);
    }

    public function test_teachers_still_cannot_manage_tables(): void
    {
        $this->actingAs($this->kisi(Role::Teacher))->get(route('admin.tables.index'))->assertForbidden();
    }

    // --- Bolum ve sabit uye -----------------------------------------------------

    public function test_a_table_gets_an_area_and_a_fixed_member(): void
    {
        $yonetici = $this->kisi(Role::Admin);
        $ayse = $this->kisi(Role::Student, ['name' => 'Ayşe Sabit']);

        $this->actingAs($yonetici)->post(route('admin.tables.store'), [
            'name' => 'Masa 7', 'area' => '  Üst kat ', 'assigned_student_id' => $ayse->id,
        ])->assertRedirect(route('admin.tables.index'));

        $masa = StudyTable::where('name', 'Masa 7')->firstOrFail();
        $this->assertSame('Üst kat', $masa->area);
        $this->assertSame($ayse->id, $masa->assigned_student_id);
        $this->assertMatchesRegularExpression('/^MASA-/', $masa->qr_code);

        // Bos birakilinca temizlenir.
        $this->actingAs($yonetici)->put(route('admin.tables.update', $masa), ['name' => 'Masa 7', 'area' => '', 'assigned_student_id' => '', 'is_active' => '1']);
        $this->assertNull($masa->fresh()->area);
        $this->assertNull($masa->fresh()->assigned_student_id);
    }

    public function test_only_a_student_can_be_the_fixed_member(): void
    {
        $yonetici = $this->kisi(Role::Admin);
        $veli = $this->kisi(Role::Parent);

        $this->actingAs($yonetici)->post(route('admin.tables.store'), ['name' => 'Masa 8', 'assigned_student_id' => $veli->id])
            ->assertSessionHasErrors('assigned_student_id');
    }

    public function test_the_list_groups_by_area_and_shows_who_is_sitting(): void
    {
        $yonetici = $this->kisi(Role::Admin);
        $ust = StudyTable::create(['name' => 'Masa 10', 'area' => 'Üst kat']);
        StudyTable::create(['name' => 'Masa 2', 'area' => 'Üst kat']);
        StudyTable::create(['name' => 'Masa 1']);
        StudyTable::create(['name' => 'Masa 5', 'area' => 'Sessiz oda', 'assigned_student_id' => $this->kisi(Role::Student, ['name' => 'Sabit Selin'])->id]);
        StudySession::create(['student_id' => $this->kisi(Role::Student, ['name' => 'Oturan Okan'])->id, 'study_table_id' => $ust->id, 'started_at' => now()->subHour()]);

        $this->actingAs($yonetici)->get(route('admin.tables.index'))
            ->assertOk()
            ->assertSeeInOrder(['Sessiz oda', 'Masa 5', 'Sabit: Sabit Selin', 'Üst kat', 'Masa 2', 'Masa 10', 'Dolu', 'Oturan Okan', 'Bölümsüz', 'Masa 1', 'Boş']);
    }

    public function test_another_member_is_warned_but_not_blocked_at_a_fixed_table(): void
    {
        $sahibi = $this->kisi(Role::Student);
        $masa = StudyTable::create(['name' => 'Masa 3', 'assigned_student_id' => $sahibi->id]);

        $baskasi = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->actingAs($baskasi)->get(route('table.session', $masa->qr_code))
            ->assertOk()
            ->assertSee('Bu masa sabit bir üyeye ayrılmış')
            ->assertSee(route('table.session.start', $masa->qr_code), false);

        $this->actingAs($sahibi)->get(route('table.session', $masa->qr_code))
            ->assertOk()
            ->assertDontSee('Bu masa sabit bir üyeye ayrılmış');
    }
}
