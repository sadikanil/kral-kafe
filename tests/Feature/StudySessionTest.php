<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dalga 3 - Calisma oturumu + canli ekran (MVP #2, #9).
 *
 * Sistemin kalbi. Zorluk mutlu yolda degil, uc durumlarda: cift baslatma,
 * masa degisimi, unutulan cikis, yanlis okutma.
 */
class StudySessionTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->withPackage(\App\Models\Package::factory()->tier3())->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function masa(string $ad = 'Masa 1'): StudyTable
    {
        return StudyTable::create(['name' => $ad]);
    }

    public function test_the_scan_page_shows_the_table(): void
    {
        $masa = $this->masa('Pencere Kenarı');

        $this->actingAs($this->ogrenci())
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Pencere Kenarı');
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $masa = $this->masa();

        $this->get(route('table.scan', $masa->qr_code))->assertRedirect(route('login'));
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->actingAs($this->ogrenci())
            ->get(route('table.scan', 'MASA-YOKBOYLE'))
            ->assertNotFound();
    }

    public function test_a_student_can_start_a_session_by_scanning(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        $this->actingAs($ogrenci)
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('session.timer')); // Dalga 23: QR masayi secer, calisma sayacta

        $oturum = StudySession::sole();
        $this->assertSame($ogrenci->id, $oturum->student_id);
        $this->assertSame($masa->id, $oturum->study_table_id);
        $this->assertNull($oturum->ended_at);
    }

    /**
     * Ogrenci butona iki kez basar, mobilde POST yeniden gonderilir. Ikinci
     * istek YENI bir oturum acmamali ve hata da gostermemeli.
     */
    public function test_starting_twice_does_not_open_a_second_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('session.timer')); // Dalga 23: QR masayi secer, calisma sayacta

        $this->assertSame(1, StudySession::count());
    }

    /**
     * Uygulama katmani tek basina yetmez: iki es zamanli istek ikisi de "acik
     * oturum yok" gorebilir. Kisitin VERITABANINDA olmasi gerekiyor.
     */
    public function test_the_database_itself_refuses_a_second_open_session(): void
    {
        $ogrenci = $this->ogrenci();
        $a = $this->masa('Masa A');
        $b = $this->masa('Masa B');

        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $a->id,
            'started_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $b->id,
            'started_at' => now(),
        ]);
    }

    /** Kapali oturumlar kisiti tetiklememeli, yoksa ogrenci gunde bir kez calisabilir. */
    public function test_a_closed_session_does_not_block_a_new_one(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHour(),
            'duration_minutes' => 120,
            'end_reason' => SessionEndReason::Manual->value,
        ]);

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $this->assertSame(2, StudySession::count());
        $this->assertSame(1, StudySession::whereNull('ended_at')->count());
    }

    public function test_scanning_another_table_moves_the_session(): void
    {
        $ogrenci = $this->ogrenci();
        $a = $this->masa('Masa A');
        $b = $this->masa('Masa B');

        // Sabit gunduz saati: gercek saat kapanistan (21:00) sonraysa acik
        // oturum kendiliginden kapanir ve test gunun saatine bagli kalirdi.
        $simdi = Carbon::parse('2026-09-16 14:00', config('kafe.timezone'));
        Carbon::setTestNow($simdi->copy()->subMinutes(45));
        $this->actingAs($ogrenci)->post(route('table.session.start', $a->qr_code));
        Carbon::setTestNow($simdi);

        $this->actingAs($ogrenci)->post(route('table.session.start', $b->qr_code));

        $eski = StudySession::where('study_table_id', $a->id)->sole();
        $this->assertNotNull($eski->ended_at);
        $this->assertSame(SessionEndReason::Switched, $eski->end_reason);
        $this->assertSame(45, $eski->duration_minutes);

        $yeni = StudySession::where('study_table_id', $b->id)->sole();
        $this->assertNull($yeni->ended_at);
    }

    public function test_a_student_can_end_a_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        // Sabit gunduz saati: gercek saat kapanistan (21:00) sonraysa acik
        // oturum kendiliginden kapanir ve test gunun saatine bagli kalirdi.
        $simdi = Carbon::parse('2026-09-16 14:00', config('kafe.timezone'));
        Carbon::setTestNow($simdi->copy()->subMinutes(90));
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));
        Carbon::setTestNow($simdi);

        $this->actingAs($ogrenci)->post(route('session.end'))->assertRedirect();

        $oturum = StudySession::sole();
        $this->assertNotNull($oturum->ended_at);
        $this->assertSame(90, $oturum->duration_minutes);
        $this->assertSame(SessionEndReason::Manual, $oturum->end_reason);
    }

    public function test_ending_without_an_open_session_is_harmless(): void
    {
        $this->actingAs($this->ogrenci())->post(route('session.end'))->assertRedirect();

        $this->assertSame(0, StudySession::count());
    }

    /**
     * Yanlis okutma / cift dokunus. Kayit SILINMEZ - veriyi yok etmek denetimi
     * imkansiz kilar - ama istatistige girmez.
     */
    public function test_a_very_short_session_is_recorded_but_not_counted(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        Carbon::setTestNow(now()->subSeconds(30));
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));
        Carbon::setTestNow();

        $this->actingAs($ogrenci)->post(route('session.end'));

        $this->assertSame(1, StudySession::count());
        $this->assertSame(0, StudySession::countable()->count());
    }

    public function test_an_inactive_table_cannot_start_a_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();
        $masa->update(['is_active' => false]);

        $this->actingAs($ogrenci)
            ->post(route('table.session.start', $masa->qr_code))
            ->assertSessionHas('error');

        $this->assertSame(0, StudySession::count());
    }

    /** Gecmis kayit, masayi silmek icin feda edilmemeli. */
    public function test_a_table_with_sessions_cannot_be_deleted(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $masa->id,
            'started_at' => now(),
        ]);

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($yonetici)
            ->delete(route('admin.tables.destroy', $masa))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('study_tables', ['id' => $masa->id]);
    }

    public function test_the_live_screen_lists_open_sessions(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 9');

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        $this->actingAs($yonetici)
            ->get(route('admin.live'))
            ->assertOk()
            ->assertSee($ogrenci->name)
            ->assertSee('Masa 9');
    }

    public function test_a_student_cannot_see_the_live_screen(): void
    {
        $this->actingAs($this->ogrenci())->get(route('admin.live'))->assertForbidden();
    }

    /** Kisminin gercekten KISMI oldugunun kaniti: indeks tanimi ended_at sartini tasimali. */
    public function test_the_unique_index_is_partial(): void
    {
        $surucu = DB::connection()->getDriverName();

        $tanim = match ($surucu) {
            'sqlite' => DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', 'study_sessions_tek_acik_oturum')
                ->value('sql'),
            'pgsql' => DB::selectOne(
                "select indexdef from pg_indexes where indexname = 'study_sessions_tek_acik_oturum'"
            )?->indexdef,
            default => $this->markTestSkipped("Bu surucu desteklenmiyor: {$surucu}"),
        };

        $this->assertNotNull($tanim, 'Kismi tekil indeks bulunamadi.');
        $this->assertStringContainsStringIgnoringCase('ended_at', (string) $tanim);
    }
    /**
     * Spec: "cikista ayni QR veya PANELDEN tek buton". Ogrenci telefonunu
     * cebine koyup kalkarsa QR ekranina geri donmesi gerekmemeli.
     */
    public function test_the_dashboard_shows_the_open_session_with_an_end_button(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 4');

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $this->actingAs($ogrenci)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Masa 4')
            ->assertSee(route('session.end'), false);
    }

    public function test_the_dashboard_has_no_session_card_when_nothing_is_open(): void
    {
        $this->actingAs($this->ogrenci())
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee(route('session.end'), false);
    }
}
