<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\PauseKind;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\ExamEvent;
use App\Models\Package;
use App\Models\SessionPause;
use App\Models\StudyLog;
use App\Models\StudyPlanItem;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smoke: ogrencinin calisma akisi uctan uca.
 *
 * QR okuyucu, elle kod, masa ekrani, baslat, masa degistir, iki kisilik
 * masa (A/B ayri kayit), duraklat/mola/ogle/devam, calisma kaydi, bitir,
 * panel, plan (tamamla, serbest deneme koy/cikar), haftalik rapor.
 *
 * Saat: 29 Eylul 2026 Sali 14:00 (kafe saati). Kafe 21:00'de kapaniyor ve
 * acik oturumlar sonrasinda kendiliginden kapaniyor; sabit gunduz saati sart.
 * Hafta: 28 Eylul (Pzt) - 4 Ekim (Paz). Biten son hafta: 21-27 Eylul.
 */
class StudentStudySmokeTest extends TestCase
{
    use RefreshDatabase;

    private const HAFTA = '2026-09-28';
    private const GECEN_HAFTA = '2026-09-21';

    protected function setUp(): void
    {
        parent::setUp();
        $this->saat('2026-09-29 14:00');
    }

    // --- Yardimcilar ---------------------------------------------------------

    /** Kafe saatiyle ana git. */
    private function saat(string $yerel): void
    {
        $this->travelTo(Carbon::parse($yerel, config('kafe.timezone')));
    }

    private function yerel(string $yerel): Carbon
    {
        return Carbon::parse($yerel, config('kafe.timezone'))->utc();
    }

    private function ogrenci(?Package $paket = null, array $ek = []): User
    {
        return User::factory()->student()
            ->withPackage($paket ?? Package::factory()->tier3()->create())
            ->create(array_merge(['name' => 'Ayşe Yılmaz', 'grade' => '12', 'field' => 'say'], $ek));
    }

    private function veli(User $ogrenci): User
    {
        $veli = User::factory()->parent()->create(['name' => 'Fatma Yılmaz']);
        \App\Models\StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        return $veli;
    }

    private function koc(User $ogrenci): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value, 'name' => 'Koç Şükrü']);
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

    private function masa(string $ad = 'Masa 1', ?string $kod = null, bool $aktif = true): StudyTable
    {
        $masa = new StudyTable(['name' => $ad, 'is_active' => $aktif]);
        if ($kod !== null) {
            $masa->qr_code = $kod;
        }
        $masa->save();

        return $masa;
    }

    private function acikOturum(User $ogrenci, ?StudyTable $masa = null, string $bas = '2026-09-29 13:00'): StudySession
    {
        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => ($masa ?? $this->masa('Masa ' . uniqid()))->id,
            'started_at' => $this->yerel($bas),
        ]);
    }

    private function bitmisOturum(User $ogrenci, string $bas, string $son, ApprovalStatus $durum = ApprovalStatus::Approved, ?StudyTable $masa = null): StudySession
    {
        $baslangic = $this->yerel($bas);
        $bitis = $this->yerel($son);

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => ($masa ?? $this->masa('Masa ' . uniqid()))->id,
            'started_at' => $baslangic,
            'ended_at' => $bitis,
            'duration_minutes' => (int) $baslangic->diffInMinutes($bitis),
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => $durum->value,
        ]);
    }

    private function ders(string $kod): Subject
    {
        return Subject::where('code', $kod)->sole();
    }

    private function madde(User $ogrenci, string $gun, array $ek = []): StudyPlanItem
    {
        return StudyPlanItem::create(array_merge([
            'student_id' => $ogrenci->id,
            'title' => 'Paragraf 40 soru',
            'plan_date' => $gun,
            'week_start' => \App\Support\LocalDay::weekStart($gun),
            'period' => 'week',
        ], $ek));
    }

    private function serbestDeneme(string $bas = '2026-10-01', string $son = '2026-10-31'): ExamEvent
    {
        return ExamEvent::create([
            'title' => 'Özdebir Türkiye Geneli', 'exam_type' => 'tyt', 'exam_date' => $bas,
            'is_flexible' => true, 'available_until' => $son,
        ]);
    }

    // =========================================================================
    // QR okuyucu: GET /masa-okut
    // =========================================================================

    public function test_the_scanner_page_offers_the_camera_and_the_manual_code_form(): void
    {
        $this->actingAs($this->ogrenci())
            ->get(route('table.scanner'))
            ->assertOk()
            ->assertSee('js-scanner-video', false)
            ->assertSee('Masadaki kodu yaz')
            ->assertSee(route('table.find'), false);
    }

    public function test_a_guest_is_sent_to_login_from_every_study_page(): void
    {
        $masa = $this->masa();

        foreach ([
            route('table.scanner'), route('table.scan', $masa->qr_code), route('session.timer'),
            route('user.dashboard'), route('user.plan'), route('user.report'),
        ] as $adres) {
            $this->get($adres)->assertRedirect(route('login'));
        }
    }

    public function test_a_student_with_a_lapsed_subscription_is_sent_to_the_panel_from_the_scanner(): void
    {
        $ogrenci = $this->ogrenci(null, ['subscription_status' => 'inactive']);

        $this->actingAs($ogrenci)->get(route('table.scanner'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error');

        // Panel yine acilir ki mesaj gorunsun.
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk();
    }

    // =========================================================================
    // Elle kod: POST /masa-bul
    // =========================================================================

    public function test_the_manual_code_is_found_even_when_typed_lowercase_with_spaces(): void
    {
        $masa = $this->masa('Pencere Kenarı', 'MASA-K7Q2P9XZ');

        $this->actingAs($this->ogrenci())
            ->post(route('table.find'), ['code' => '  masa-k7q2p9xz '])
            ->assertRedirect(route('table.scan', 'MASA-K7Q2P9XZ'));
    }

    public function test_an_unknown_manual_code_goes_back_with_a_turkish_error_and_keeps_the_input(): void
    {
        $this->masa('Masa 1', 'MASA-AAAABBBB');

        $this->actingAs($this->ogrenci())
            ->from(route('table.scanner'))
            ->post(route('table.find'), ['code' => 'MASA-YOKBOYLE'])
            ->assertRedirect(route('table.scanner'))
            ->assertSessionHasErrors(['code' => 'Bu kodla bir masa bulunamadı. Masadaki etikette yazan kodu kontrol et.'])
            ->assertSessionHasInput('code', 'MASA-YOKBOYLE');

        // Hata okuyucu sayfasinda gorunur.
        $this->actingAs($this->ogrenci())->get(route('table.scanner'))->assertOk();
    }

    public function test_an_empty_manual_code_is_rejected(): void
    {
        $this->actingAs($this->ogrenci())
            ->from(route('table.scanner'))
            ->post(route('table.find'), ['code' => ''])
            ->assertRedirect(route('table.scanner'))
            ->assertSessionHasErrors('code');
    }

    // =========================================================================
    // Masa ekrani: GET /masa/{kod}
    // =========================================================================

    public function test_the_scan_page_greets_an_entitled_student_with_the_start_button(): void
    {
        $masa = $this->masa('Pencere Kenarı 3A');

        $this->actingAs($this->ogrenci())
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Pencere Kenarı 3A')
            ->assertSee('Hoş geldin Ayşe Yılmaz!')
            ->assertSee('Çalışmaya Başla')
            ->assertSee(route('table.session.start', $masa->qr_code), false);
    }

    public function test_an_unknown_table_code_is_not_found(): void
    {
        $this->actingAs($this->ogrenci())->get('/masa/MASA-YOKBOYLE')->assertNotFound();
    }

    public function test_an_exam_only_student_sees_the_package_warning_instead_of_the_button(): void
    {
        $masa = $this->masa();

        $this->actingAs($this->ogrenci(Package::factory()->examOnly()->create()))
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Paketin masa kullanımını kapsamıyor')
            ->assertDontSee('Çalışmaya Başla');
    }

    public function test_an_inactive_table_says_so_and_offers_no_button(): void
    {
        $masa = $this->masa('Masa 9', null, false);

        $this->actingAs($this->ogrenci())
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Bu masa şu anda kullanımda değil.')
            ->assertDontSee('Çalışmaya Başla');
    }

    public function test_the_scan_page_of_my_own_table_shows_the_running_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 4');
        $this->acikOturum($ogrenci, $masa, '2026-09-29 13:00');

        $this->actingAs($ogrenci)
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Çalışma sürüyor — Masa 4')
            ->assertSee('Başlangıç: 13:00')
            ->assertSee('1 sa')
            ->assertSee(route('session.timer'), false)
            ->assertSee(route('session.end'), false)
            ->assertDontSee('Çalışmaya Başla');
    }

    public function test_the_scan_page_of_another_table_offers_to_switch(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci, $this->masa('Masa 4'));
        $yeni = $this->masa('Masa 7');

        $this->actingAs($ogrenci)
            ->get(route('table.scan', $yeni->qr_code))
            ->assertOk()
            ->assertSee('Masa 4')
            ->assertSee('Bu Masaya Geç');
    }

    public function test_the_scan_page_names_the_student_already_sitting_there(): void
    {
        $masa = $this->masa('Masa 2A');
        $this->acikOturum($this->ogrenci(null, ['name' => 'Şükrü Öztürk']), $masa);

        $this->actingAs($this->ogrenci(null, ['name' => 'İpek Çağlar']))
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Bu masada')
            ->assertSee('Şükrü Öztürk');
    }

    public function test_a_parent_scanning_a_table_is_told_sessions_are_for_students_only(): void
    {
        $masa = $this->masa();

        $this->actingAs($this->veli($this->ogrenci()))
            ->get(route('table.scan', $masa->qr_code))
            ->assertOk()
            ->assertSee('Çalışma oturumu yalnızca öğrenciler içindir.')
            ->assertDontSee('Çalışmaya Başla');
    }

    // =========================================================================
    // Baslat: POST /masa/{kod}/basla
    // =========================================================================

    public function test_a_student_starts_a_session_with_location_and_lands_on_the_timer(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Pencere Kenarı 3A');

        $this->actingAs($ogrenci)
            ->post(route('table.session.start', $masa->qr_code), [
                'latitude' => '41.0082376', 'longitude' => '28.9783589', 'accuracy' => '18',
            ])
            ->assertRedirect(route('session.timer'))
            ->assertSessionHas('success', 'Pencere Kenarı 3A için çalışma başladı. Kolay gelsin!');

        $oturum = StudySession::sole();
        $this->assertSame($ogrenci->id, $oturum->student_id);
        $this->assertSame($masa->id, $oturum->study_table_id);
        $this->assertNull($oturum->ended_at);
        $this->assertEqualsWithDelta(41.0082376, (float) $oturum->latitude, 0.00001);
        $this->assertSame(ApprovalStatus::Pending, $oturum->approval_status);
    }

    /** Konum izni reddedilince gizli alanlar BOS gider; oturum yine baslamali. */
    public function test_a_denied_location_permission_still_starts_the_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        $this->actingAs($ogrenci)
            ->post(route('table.session.start', $masa->qr_code), ['latitude' => '', 'longitude' => '', 'accuracy' => ''])
            ->assertRedirect(route('session.timer'));

        $oturum = StudySession::sole();
        $this->assertNull($oturum->latitude);
        $this->assertNull($oturum->longitude);
    }

    public function test_an_out_of_range_latitude_is_rejected_without_opening_a_session(): void
    {
        $masa = $this->masa();

        $this->actingAs($this->ogrenci())
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code), ['latitude' => '200', 'longitude' => '28.97'])
            ->assertRedirect(route('table.scan', $masa->qr_code))
            ->assertSessionHasErrors('latitude');

        $this->assertSame(0, StudySession::count());
    }

    public function test_the_same_table_twice_keeps_a_single_session(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa();

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code))->assertRedirect(route('session.timer'));
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code))->assertRedirect(route('session.timer'));

        $this->assertSame(1, StudySession::count());
    }

    public function test_switching_tables_closes_the_old_session_as_switched_and_opens_a_new_one(): void
    {
        $ogrenci = $this->ogrenci();
        $eski = $this->masa('Masa 4');
        $yeni = $this->masa('Masa 7');
        $ilk = $this->acikOturum($ogrenci, $eski, '2026-09-29 13:00');

        $this->actingAs($ogrenci)
            ->post(route('table.session.start', $yeni->qr_code))
            ->assertRedirect(route('session.timer'))
            ->assertSessionHas('success', 'Masa 7 için çalışma başladı. Kolay gelsin!');

        $ilk->refresh();
        $this->assertNotNull($ilk->ended_at);
        $this->assertSame(SessionEndReason::Switched, $ilk->end_reason);
        $this->assertSame(60, $ilk->duration_minutes);

        $acik = StudySession::open()->sole();
        $this->assertSame($yeni->id, $acik->study_table_id);
    }

    /** Iki kisilik masa: A ve B sandalyeleri ayri kayit, ikisi de baslayabilmeli. */
    public function test_two_students_on_the_a_and_b_seats_of_one_table_both_start(): void
    {
        $a = $this->masa('Masa 3 A');
        $b = $this->masa('Masa 3 B');
        $ayse = $this->ogrenci(null, ['name' => 'Ayşe']);
        $ilker = $this->ogrenci(null, ['name' => 'İlker']);

        $this->actingAs($ayse)->post(route('table.session.start', $a->qr_code))->assertRedirect(route('session.timer'));
        $this->actingAs($ilker)->post(route('table.session.start', $b->qr_code))->assertRedirect(route('session.timer'));

        $this->assertSame($a->id, StudySession::open()->where('student_id', $ayse->id)->sole()->study_table_id);
        $this->assertSame($b->id, StudySession::open()->where('student_id', $ilker->id)->sole()->study_table_id);
        $this->assertSame(['total' => 2, 'inside' => 2, 'free' => 0], StudyTable::occupancy());

        // Her biri kendi sayacini gorur.
        $this->actingAs($ilker)->get(route('session.timer'))->assertOk()->assertSee('Masa 3 B');
    }

    public function test_an_inactive_table_refuses_to_start(): void
    {
        $masa = $this->masa('Masa 9', null, false);

        $this->actingAs($this->ogrenci())
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('table.scan', $masa->qr_code))
            ->assertSessionHas('error', 'Bu masa şu anda kullanımda değil.');

        $this->assertSame(0, StudySession::count());
    }

    public function test_an_exam_only_package_cannot_start_a_session(): void
    {
        $masa = $this->masa();

        $this->actingAs($this->ogrenci(Package::factory()->examOnly()->create()))
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect(route('table.scan', $masa->qr_code))
            ->assertSessionHas('error', 'Paketin masa kullanımını kapsamıyor. Yöneticiye danış.');

        $this->assertSame(0, StudySession::count());
    }

    public function test_a_parent_cannot_start_a_session(): void
    {
        $masa = $this->masa();

        $this->actingAs($this->veli($this->ogrenci()))
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code))
            ->assertSessionHas('error', 'Çalışma oturumu yalnızca öğrenciler içindir.');

        $this->assertSame(0, StudySession::count());
    }

    // =========================================================================
    // Sayac: GET /calisma
    // =========================================================================

    public function test_the_timer_shows_the_running_session_with_pause_buttons_and_curriculum_subjects(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci, $this->masa('Pencere Kenarı 3A'), '2026-09-29 12:30');

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('Çalışma · Pencere Kenarı 3A')
            ->assertSee('Çalışıyorsun')
            ->assertSee('01:30')
            ->assertSee('Duraklat')
            ->assertSee('15 dk mola')
            ->assertSee('Öğle arası')
            ->assertSee('AYT Fizik')        // 12. sinif sayisal
            ->assertDontSee('Tarih-2')      // sozel dersi sayisalciya gosterme
            ->assertSee('Bitirdiğin her şeyi buraya yaz');
    }

    public function test_the_timer_without_an_open_session_goes_to_the_panel(): void
    {
        $this->actingAs($this->ogrenci())->get(route('session.timer'))->assertRedirect(route('user.dashboard'));
    }

    public function test_after_closing_time_the_forgotten_session_is_closed_and_the_timer_goes_to_the_panel(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci, null, '2026-09-29 13:00');

        $this->saat('2026-09-29 22:15');

        $this->actingAs($ogrenci)->get(route('session.timer'))->assertRedirect(route('user.dashboard'));

        $oturum->refresh();
        $this->assertSame(SessionEndReason::AutoClosed, $oturum->end_reason);
        $this->assertTrue($oturum->ended_at->equalTo($this->yerel('2026-09-29 21:00')));
        $this->assertSame(8 * 60, $oturum->duration_minutes);
    }

    /**
     * Kapanistan (21:00) sonra baslatilan oturum gece boyu acik kalmamali.
     *
     * Bugun: 21:20'de baslayan oturumun kapanisi ertesi gunun 21:00'i, 12
     * saat siniri 09:20. Ogrenci 09:05'te gelince panelde dunku "11:45"
     * sayaci goruyor; ayni masayi okutursa dunku oturuma devam ediyor ve
     * 09:20'de "sure asimi" ile atiliyor; baska masayi okutursa 705
     * dakikalik oturum "masa degistirdi" diye (anomali DEGIL) kaydoluyor.
     */
    public function test_a_session_started_after_closing_does_not_run_overnight(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 1');
        $diger = $this->masa('Masa 2');

        $this->saat('2026-09-29 21:20');
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $this->saat('2026-09-30 09:05');
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()->assertDontSee('11:45');
        $this->assertSame(0, StudySession::open()->where('started_at', '<', $this->yerel('2026-09-30 00:00'))->count());

        $this->actingAs($ogrenci)->post(route('table.session.start', $diger->qr_code));
        $this->assertFalse(StudySession::where('duration_minutes', '>', 60)->exists());
    }

    /** Gun siniri: dunun 23:50 kaydi bugunun listesinde gorunmemeli. */
    public function test_the_timer_lists_only_todays_logs_by_cafe_time(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $dun = StudyLog::create(['student_id' => $ogrenci->id, 'study_session_id' => $oturum->id, 'amount' => 777, 'unit' => 'soru']);
        $dun->forceFill(['created_at' => $this->yerel('2026-09-28 23:50')])->save();
        $bugun = StudyLog::create(['student_id' => $ogrenci->id, 'study_session_id' => $oturum->id, 'amount' => 35, 'unit' => 'sayfa']);
        $bugun->forceFill(['created_at' => $this->yerel('2026-09-29 00:10')])->save();

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('Genel · 35 sayfa')
            ->assertDontSee('777 soru');
    }

    // =========================================================================
    // Duraklat / devam: POST /oturum/duraklat, /oturum/devam
    // =========================================================================

    public function test_a_15_minute_break_pauses_the_session_and_the_timer_shows_the_countdown(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'break'])
            ->assertRedirect(route('session.timer'));

        $mola = SessionPause::sole();
        $this->assertSame($oturum->id, $mola->study_session_id);
        $this->assertSame(PauseKind::Break, $mola->kind);
        $this->assertNull($mola->ended_at);

        $this->travel(5)->minutes();

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('⏸ Mola')
            ->assertSee('Molanın bitmesine')
            ->assertSee('10:00')
            ->assertSee('Devam et')
            ->assertDontSee('15 dk mola</button>', false);
    }

    public function test_pressing_pause_twice_keeps_one_open_pause(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'pause']);
        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'lunch'])->assertRedirect(route('session.timer'));

        $this->assertSame(1, SessionPause::count());
        $this->assertSame(PauseKind::Pause, SessionPause::sole()->kind);
    }

    public function test_an_unknown_pause_kind_is_rejected(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.pause'), ['tur' => 'uyku'])
            ->assertRedirect(route('session.timer'))
            ->assertSessionHasErrors('tur');

        $this->assertSame(0, SessionPause::count());
    }

    public function test_pausing_without_a_session_goes_to_the_panel_with_an_error(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('session.pause'), ['tur' => 'break'])
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error', 'Açık bir çalışma oturumunuz yok.');
    }

    public function test_lunch_then_resume_does_not_count_the_lunch_hour(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 5');

        $this->saat('2026-09-29 11:00');
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $this->saat('2026-09-29 12:00');
        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'lunch'])->assertRedirect(route('session.timer'));

        $this->saat('2026-09-29 13:00');
        $this->actingAs($ogrenci)->post(route('session.resume'))->assertRedirect(route('session.timer'));
        $this->assertNotNull(SessionPause::sole()->ended_at);

        $this->saat('2026-09-29 14:00');
        $this->actingAs($ogrenci)->get(route('session.timer'))->assertOk()->assertSee('02:00')->assertSee('Çalışıyorsun');

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.end'))
            ->assertSessionHas('success', 'Çalışma bitti. Süre: 120 dakika.');

        $this->assertSame(120, StudySession::sole()->duration_minutes);
    }

    public function test_resume_without_a_session_is_harmless(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->post(route('session.resume'))->assertRedirect(route('session.timer'));
        $this->actingAs($ogrenci)->get(route('session.timer'))->assertRedirect(route('user.dashboard'));
    }

    // =========================================================================
    // Bitir: POST /oturum/bitir
    // =========================================================================

    public function test_ending_from_the_timer_closes_the_session_and_the_panel_shows_the_duration(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci, $this->masa('Masa 4'), '2026-09-29 13:00');

        $this->actingAs($ogrenci)
            ->followingRedirects()
            ->from(route('session.timer'))
            ->post(route('session.end'))
            ->assertOk()
            ->assertSee('Çalışma bitti. Süre: 60 dakika.')
            ->assertSee('Çalışmaya başla')
            // Onay bekleyen oturum panelde gorunur, sebebi belli.
            ->assertSee('Henüz sayılmayan oturumlar')
            ->assertSee('Onay bekliyor');

        $oturum = StudySession::sole();
        $this->assertSame(SessionEndReason::Manual, $oturum->end_reason);
        $this->assertSame(60, $oturum->duration_minutes);
    }

    public function test_ending_during_lunch_closes_the_pause_too(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci, null, '2026-09-29 12:00');
        $oturum->pauses()->create(['kind' => 'lunch', 'started_at' => $this->yerel('2026-09-29 13:30')]);

        $this->actingAs($ogrenci)->from(route('user.dashboard'))
            ->post(route('session.end'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('success', 'Çalışma bitti. Süre: 90 dakika.');

        $this->assertNotNull(SessionPause::sole()->ended_at);
    }

    public function test_ending_twice_keeps_the_first_close(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci, null, '2026-09-29 13:00');

        $this->actingAs($ogrenci)->from(route('user.dashboard'))->post(route('session.end'));
        $ilkBitis = StudySession::sole()->ended_at;

        $this->travel(3)->minutes();

        $this->actingAs($ogrenci)->from(route('user.dashboard'))
            ->post(route('session.end'))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error', 'Açık bir çalışma oturumunuz yok.');

        $this->assertTrue(StudySession::sole()->ended_at->equalTo($ilkBitis));
        $this->assertSame(60, StudySession::sole()->duration_minutes);
    }

    // =========================================================================
    // Calisma kaydi: POST /oturum/kayit, DELETE /oturum/kayit/{log}
    // =========================================================================

    public function test_a_log_with_turkish_note_is_saved_and_sets_the_session_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $fizik = $this->ders('ayt_fizik');

        $this->actingAs($ogrenci)
            ->post(route('session.logs.store'), [
                'subject_id' => $fizik->id, 'amount' => '200', 'unit' => 'soru',
                'note' => 'Tork ve denge — ağır, şaşırtıcı, çığ gibi İŞ',
            ])
            ->assertRedirect(route('session.timer'))
            ->assertSessionHas('success');

        $kayit = StudyLog::sole();
        $this->assertSame($oturum->id, $kayit->study_session_id);
        $this->assertSame(200, $kayit->amount);
        $this->assertSame('Tork ve denge — ağır, şaşırtıcı, çığ gibi İŞ', $kayit->note);
        $this->assertSame($fizik->id, $oturum->fresh()->subject_id);

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('AYT Fizik · 200 soru')
            ->assertSee('Tork ve denge — ağır, şaşırtıcı, çığ gibi İŞ')
            ->assertSee('Bugün:');
    }

    public function test_a_general_log_does_not_erase_the_previous_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $fizik = $this->ders('ayt_fizik');
        $oturum->update(['subject_id' => $fizik->id]);

        $this->actingAs($ogrenci)
            ->post(route('session.logs.store'), ['subject_id' => '', 'amount' => '1', 'unit' => 'deneme'])
            ->assertRedirect(route('session.timer'));

        $this->assertNull(StudyLog::sole()->subject_id);
        $this->assertSame($fizik->id, $oturum->fresh()->subject_id);
    }

    public function test_a_zero_amount_or_unknown_unit_is_rejected(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.logs.store'), ['amount' => '0', 'unit' => 'soru'])
            ->assertRedirect(route('session.timer'))
            ->assertSessionHasErrors(['amount' => 'En az 1 olmalı.']);

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.logs.store'), ['amount' => '10', 'unit' => 'dakika'])
            ->assertSessionHasErrors('unit');

        $this->assertSame(0, StudyLog::count());
    }

    public function test_logging_without_an_open_session_goes_to_the_panel(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('session.logs.store'), ['amount' => '50', 'unit' => 'soru'])
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('error', 'Açık bir çalışma oturumunuz yok.');

        $this->assertSame(0, StudyLog::count());
    }

    public function test_a_student_deletes_their_own_log_while_the_session_is_open(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $kayit = StudyLog::create(['student_id' => $ogrenci->id, 'study_session_id' => $oturum->id, 'amount' => 20, 'unit' => 'sayfa']);

        $this->actingAs($ogrenci)->delete(route('session.logs.destroy', $kayit))->assertRedirect(route('session.timer'));

        $this->assertModelMissing($kayit);
    }

    public function test_another_students_log_cannot_be_deleted(): void
    {
        $sahibi = $this->ogrenci(null, ['name' => 'Şule']);
        $kayit = StudyLog::create(['student_id' => $sahibi->id, 'study_session_id' => $this->acikOturum($sahibi)->id, 'amount' => 20, 'unit' => 'sayfa']);

        $this->actingAs($this->ogrenci(null, ['name' => 'Ömer']))
            ->delete(route('session.logs.destroy', $kayit))
            ->assertForbidden();

        $this->assertModelExists($kayit);
    }

    public function test_a_log_of_a_finished_session_cannot_be_deleted(): void
    {
        $ogrenci = $this->ogrenci();
        $bitmis = $this->bitmisOturum($ogrenci, '2026-09-29 09:00', '2026-09-29 11:00');
        $kayit = StudyLog::create(['student_id' => $ogrenci->id, 'study_session_id' => $bitmis->id, 'amount' => 20, 'unit' => 'sayfa']);

        $this->actingAs($ogrenci)->delete(route('session.logs.destroy', $kayit))->assertForbidden();

        $this->assertModelExists($kayit);
    }

    // =========================================================================
    // Panel: GET /kullanici/panel
    // =========================================================================

    public function test_the_panel_with_an_open_session_shows_the_live_card(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci, $this->masa('Pencere Kenarı 3A'), '2026-09-29 12:15');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Hoş Geldin, Ayşe Yılmaz!')
            ->assertSee('Çalışıyorsun · Pencere Kenarı 3A')
            ->assertSee('01:45')
            ->assertSee('giriş 12:15')
            ->assertSee(route('session.timer'), false)
            ->assertDontSee('Masandaki QR\'ı okut', false);
    }

    public function test_the_panel_shows_today_plan_credited_minutes_and_pending_sessions(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-29', ['title' => 'Paragraf 40 soru', 'subject_id' => $this->ders('tyt_turkce')->id]);
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Yarının maddesi']);
        $this->bitmisOturum($ogrenci, '2026-09-29 09:00', '2026-09-29 11:30', ApprovalStatus::Approved, $this->masa('Masa 1'));
        $this->bitmisOturum($ogrenci, '2026-09-29 12:00', '2026-09-29 12:45', ApprovalStatus::Pending, $this->masa('Masa 2'));

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Çalışmaya başla')
            ->assertSee(route('table.scanner'), false)
            ->assertSee('Paragraf 40 soru')
            ->assertSee('TYT Türkçe')
            ->assertDontSee('Yarının maddesi')
            ->assertSee('✓ Bitti')
            ->assertSee('<div class="mini-stat-value">2 sa 30 dk</div>', false)
            ->assertSee('Henüz sayılmayan oturumlar')
            ->assertSee('Masa 2')
            ->assertSee('45 dk')
            ->assertSee('Onay bekliyor');
    }

    public function test_a_brand_new_student_without_anything_gets_a_clean_panel(): void
    {
        $ogrenci = User::factory()->student()->create(['name' => 'Yeni Öğrenci']);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Bugün için plan yok.')
            ->assertSee('0 dk')
            ->assertDontSee('Çalışmaya başla'); // paketi yok: masa hakki yok
    }

    /**
     * Ogretmen ve gorevlinin ana sayfasi ogrenci paneli (Role::homeRoute).
     * "Calismaya basla" onlari okuyucuya, oradan "yalnizca ogrenciler"
     * uyarisina goturur: cikmaz.
     */
    public function test_a_teacher_home_panel_does_not_invite_them_to_scan(): void
    {
        $ogretmen = User::factory()->create(['role' => Role::Teacher->value, 'subscription_status' => 'active']);

        $this->actingAs($ogretmen)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('Masandaki QR\'ı okut', false);
    }

    /** Ogrenci olmayan hesap ogrenci uclarindan kendi adina veri yazmamali. */
    public function test_a_parent_opening_student_pages_writes_no_student_data(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);
        $deneme = $this->serbestDeneme();

        $this->actingAs($veli)->get(route('user.report'));
        $this->actingAs($veli)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10']);

        $this->assertSame(0, WeeklyReport::where('student_id', $veli->id)->count());
        $this->assertSame(0, StudyPlanItem::where('student_id', $veli->id)->count());
    }

    public function test_an_exam_only_student_is_not_invited_to_scan(): void
    {
        $this->actingAs($this->ogrenci(Package::factory()->examOnly()->create()))
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('Masandaki QR\'ı okut', false);
    }

    /**
     * Sayac "Net calisma · molalar sayilmaz" diyor ve oturumun
     * duration_minutes'i net. Onaylandiktan sonra paneldeki "Bugun" / "Bu
     * hafta" da NET olmali; ogle arasi calisma sayilmamali.
     */
    public function test_the_panel_totals_do_not_credit_the_lunch_break(): void
    {
        $ogrenci = $this->ogrenci();
        $masa = $this->masa('Masa 5');
        $yonetici = User::factory()->admin()->create();

        $this->saat('2026-09-29 09:00');
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));
        $this->saat('2026-09-29 10:00');
        $this->actingAs($ogrenci)->post(route('session.pause'), ['tur' => 'lunch']);
        $this->saat('2026-09-29 11:00');
        $this->actingAs($ogrenci)->post(route('session.resume'));
        $this->saat('2026-09-29 12:00');
        $this->actingAs($ogrenci)->from(route('session.timer'))->post(route('session.end'))
            ->assertSessionHas('success', 'Çalışma bitti. Süre: 120 dakika.');

        $oturum = StudySession::sole();
        $this->assertSame(120, $oturum->duration_minutes);
        $this->assertTrue($oturum->approve($yonetici));

        $this->saat('2026-09-29 14:00');
        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('<div class="mini-stat-value">2 sa</div>', false)
            ->assertDontSee('<div class="mini-stat-value">3 sa</div>', false);
    }

    // =========================================================================
    // Plan: GET /kullanici/plan, POST tamamla, POST/DELETE serbest deneme
    // =========================================================================

    public function test_the_plan_shows_the_week_with_items_and_the_flexible_exam_form(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Vektörler ve Kuvvet', 'subject_id' => $this->ders('ayt_fizik')->id]);
        $this->serbestDeneme();

        $this->actingAs($ogrenci)->get(route('user.plan'))
            ->assertOk()
            ->assertSee('28 Eylül – 4 Ekim 2026')
            ->assertSee('Vektörler ve Kuvvet')
            ->assertSee('AYT Fizik')
            ->assertSee('✓ Bitti')
            ->assertSee('Serbest denemeyi planına koy')
            ->assertSee('Özdebir Türkiye Geneli')
            ->assertSee('min="2026-10-01"', false)
            ->assertSee('max="2026-10-31"', false)
            ->assertSee(route('user.plan', ['hafta' => '2026-09-21']), false)
            ->assertSee(route('user.plan', ['hafta' => '2026-10-05']), false);
    }

    /**
     * Pazar haftanin son gunu. SQLite'ta 'date' cast'i '2026-10-04 00:00:00'
     * yaziyor ve WeekPlan'in whereBetween(..., '2026-10-04') ust siniri onu
     * disarida birakiyor (Postgres'te date sutunu oldugu icin sorun yok).
     */
    public function test_a_plan_item_on_sunday_is_shown_in_its_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-10-04', ['title' => 'Pazar tekrarı']);

        $this->actingAs($ogrenci)->get(route('user.plan'))
            ->assertOk()
            ->assertSee('Pazar tekrarı');
    }

    public function test_a_garbage_week_parameter_falls_back_to_this_week(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.plan', ['hafta' => 'dün-değil']))
            ->assertOk()
            ->assertSee('28 Eylül – 4 Ekim 2026');
    }

    public function test_the_student_without_exam_club_does_not_see_the_flexible_exam_form(): void
    {
        $this->serbestDeneme();

        $this->actingAs($this->ogrenci(Package::factory()->tier1()->create()))
            ->get(route('user.plan'))
            ->assertOk()
            ->assertDontSee('Serbest denemeyi planına koy');
    }

    public function test_the_student_completes_their_own_item_once(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci, '2026-09-29');

        $this->actingAs($ogrenci)->from(route('user.dashboard'))
            ->post(route('user.study-plan.complete', $madde))
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('success', 'Tamamlandı olarak işaretlendi.');

        $madde->refresh();
        $this->assertSame('done', $madde->status);
        $ilk = $madde->completed_at;

        $this->travel(10)->minutes();
        $this->actingAs($ogrenci)->from(route('user.dashboard'))->post(route('user.study-plan.complete', $madde));
        $this->assertTrue($madde->fresh()->completed_at->equalTo($ilk));

        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()->assertSee('1 / 1');
    }

    public function test_another_students_item_cannot_be_completed(): void
    {
        $madde = $this->madde($this->ogrenci(null, ['name' => 'Şule']), '2026-09-29');

        $this->actingAs($this->ogrenci(null, ['name' => 'Ömer']))
            ->post(route('user.study-plan.complete', $madde))
            ->assertForbidden();

        $this->assertSame('open', $madde->fresh()->status);
    }

    public function test_the_student_schedules_a_flexible_exam_inside_the_window(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbestDeneme();

        $this->actingAs($ogrenci)
            ->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10'])
            ->assertRedirect(route('user.plan', ['hafta' => '2026-10-10']))
            ->assertSessionHas('success', 'Deneme planına eklendi.');

        $madde = StudyPlanItem::sole();
        $this->assertSame('Özdebir Türkiye Geneli (TYT)', $madde->title);
        $this->assertSame('2026-10-10', $madde->plan_date->toDateString());
        $this->assertSame('2026-10-05', $madde->week_start->toDateString());
        $this->assertSame($ogrenci->id, $madde->created_by);

        $this->actingAs($ogrenci)->get(route('user.plan', ['hafta' => '2026-10-10']))
            ->assertOk()
            ->assertSee('Özdebir Türkiye Geneli (TYT)')
            ->assertSee('Çıkar');
    }

    public function test_a_flexible_exam_cannot_be_put_on_a_past_day_of_its_window(): void
    {
        $deneme = $this->serbestDeneme('2026-09-20', '2026-10-15');

        $this->actingAs($this->ogrenci())->from(route('user.plan'))
            ->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-09-28'])
            ->assertRedirect(route('user.plan'))
            ->assertSessionHasErrors('plan_date');

        // Bugun (pencerenin ortasi) kabul edilir.
        $this->actingAs($this->ogrenci())
            ->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-09-29'])
            ->assertSessionHasNoErrors();
    }

    public function test_scheduling_needs_the_exam_club(): void
    {
        $deneme = $this->serbestDeneme();

        $this->actingAs($this->ogrenci(Package::factory()->tier2()->create()))
            ->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10'])
            ->assertForbidden();

        $this->assertSame(0, StudyPlanItem::count());
    }

    /** "Ekle"ye iki kez dokunmak ayni denemeyi takvime iki kez koymamali. */
    public function test_double_tapping_add_does_not_schedule_the_same_flexible_exam_twice(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbestDeneme();

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10']);
        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10']);

        $this->assertSame(1, StudyPlanItem::where('exam_event_id', $deneme->id)->count());
    }

    public function test_the_student_removes_only_their_own_scheduled_exam(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbestDeneme();
        $benim = $this->madde($ogrenci, '2026-10-10', ['exam_event_id' => $deneme->id, 'created_by' => $ogrenci->id, 'title' => 'Özdebir (TYT)']);
        $koc = $this->koc($ogrenci);
        $kocunki = $this->madde($ogrenci, '2026-10-11', ['exam_event_id' => $deneme->id, 'created_by' => $koc->id]);
        $baskasi = $this->ogrenci(null, ['name' => 'Şule']);
        $baskasininki = $this->madde($baskasi, '2026-10-10', ['exam_event_id' => $deneme->id, 'created_by' => $baskasi->id]);
        $duzMadde = $this->madde($ogrenci, '2026-09-30', ['created_by' => $ogrenci->id]);

        $this->actingAs($ogrenci)->from(route('user.plan'))
            ->delete(route('user.plan.exam.destroy', $benim))
            ->assertRedirect(route('user.plan'))
            ->assertSessionHas('success', 'Deneme planından çıkarıldı.');

        $this->actingAs($ogrenci)->delete(route('user.plan.exam.destroy', $kocunki))->assertForbidden();
        $this->actingAs($ogrenci)->delete(route('user.plan.exam.destroy', $baskasininki))->assertForbidden();
        $this->actingAs($ogrenci)->delete(route('user.plan.exam.destroy', $duzMadde))->assertForbidden();

        $this->assertModelMissing($benim);
        $this->assertModelExists($kocunki);
        $this->assertModelExists($baskasininki);
        $this->assertModelExists($duzMadde);
    }

    // =========================================================================
    // Haftalik rapor: GET /kullanici/rapor
    // =========================================================================

    public function test_the_report_defaults_to_last_finished_week_with_credited_minutes_and_logs(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->bitmisOturum($ogrenci, '2026-09-23 10:00', '2026-09-23 12:00');
        $kayit = StudyLog::create(['student_id' => $ogrenci->id, 'study_session_id' => $oturum->id, 'amount' => 150, 'unit' => 'soru']);
        $kayit->forceFill(['created_at' => $this->yerel('2026-09-23 11:00')])->save();
        $this->madde($ogrenci, '2026-09-24', ['status' => 'done']);
        $this->madde($ogrenci, '2026-09-25');

        $this->actingAs($ogrenci)->get(route('user.report'))
            ->assertOk()
            ->assertSee('Bu rapor velinle de paylaşılıyor')
            ->assertSee('21 - 27 Eylül 2026')
            ->assertSee('Onaylanmış süre')
            ->assertSee('2 sa')
            ->assertSee('150 soru')
            ->assertSee('1 / 2');

        $rapor = WeeklyReport::sole();
        $this->assertSame(self::GECEN_HAFTA, $rapor->week_start->toDateString());
        $this->assertSame(120, $rapor->payload['minutes']);
    }

    public function test_the_current_week_report_is_not_ready_and_nothing_is_frozen(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.report', ['hafta' => '2026-09-29']))
            ->assertOk()
            ->assertSee('Hafta tamamlanınca hazır olacak');

        $this->assertSame(0, WeeklyReport::count());
    }

    public function test_a_garbage_report_week_falls_back_to_last_week(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.report', ['hafta' => 'ğşı']))
            ->assertOk()
            ->assertSee('21 - 27 Eylül 2026');
    }

    /** Rapor "Onaylanmis sure" diyor; ogle arasi onaylanmis calisma degil. */
    public function test_the_weekly_report_minutes_are_net_of_breaks(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->bitmisOturum($ogrenci, '2026-09-23 10:00', '2026-09-23 14:00');
        $oturum->pauses()->create([
            'kind' => 'lunch',
            'started_at' => $this->yerel('2026-09-23 12:00'),
            'ended_at' => $this->yerel('2026-09-23 13:00'),
        ]);
        $oturum->update(['duration_minutes' => 180]); // kapanista yazilan net sure

        $this->actingAs($ogrenci)->get(route('user.report'))->assertOk()->assertSee('3 sa');

        $this->assertSame(180, WeeklyReport::sole()->payload['minutes']);
    }
}
