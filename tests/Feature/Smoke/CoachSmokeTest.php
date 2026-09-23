<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\CoachNote;
use App\Models\Package;
use App\Models\StudentCommitment;
use App\Models\StudentParent;
use App\Models\StudyLog;
use App\Models\StudyPlanItem;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use App\Models\WeakTopic;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Koc alani - uctan uca duman testi (QA turu).
 *
 * Her koc rotasi gercekci veriyle calistirilir: plan takvimi (ekle, tasi,
 * sil, hafta gezinmesi), haftalik sabit program, notlar, zayif konular ve
 * haftalik rapor. Atanmamis ogrenciye her uc 403 vermeli.
 *
 * Saat: 29 Eylul 2026 sali 14:00 (kafe saati). Suren hafta 28 Eylul -
 * 4 Ekim; tamamlanmis son hafta 21 - 27 Eylul.
 */
class CoachSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const HAFTA = '2026-09-28';

    private const GECEN_HAFTA = '2026-09-21';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    // --- Yardimcilar ---------------------------------------------------------

    private function ogrenci(string $ad = 'Ayşe Yılmaz', array $ek = []): User
    {
        return User::factory()->student()
            ->withPackage(Package::factory()->tier3()->create())
            ->create(array_merge(['name' => $ad, 'grade' => '12', 'field' => 'say'], $ek));
    }

    private function koc(User ...$ogrenciler): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value, 'name' => 'Koç Şükrü Güneş']);

        foreach ($ogrenciler as $ogrenci) {
            $koc->coachStudents()->attach($ogrenci->id);
        }

        return $koc;
    }

    private function ders(string $kod): Subject
    {
        return Subject::where('code', $kod)->sole();
    }

    private function konu(string $dersKodu, string $ad): SubjectTopic
    {
        return SubjectTopic::where('subject_id', $this->ders($dersKodu)->id)->where('name', $ad)->sole();
    }

    private function madde(User $ogrenci, string $gun, array $ek = []): StudyPlanItem
    {
        return StudyPlanItem::create(array_merge([
            'student_id' => $ogrenci->id,
            'title' => 'Paragraf',
            'plan_date' => $gun,
            'week_start' => \App\Support\LocalDay::weekStart($gun),
            'period' => 'week',
        ], $ek));
    }

    private function oturum(User $ogrenci, string $gun, int $dakika): StudySession
    {
        $bas = Carbon::parse($gun . ' 10:00', config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addMinutes($dakika)->utc(),
            'duration_minutes' => $dakika,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);
    }

    private function planSayfasi(User $ogrenci, array $sorgu = []): string
    {
        return route('coach.plan.show', array_merge([$ogrenci], $sorgu));
    }

    // =========================================================================
    // Koc listesi (coach.plan.index)
    // =========================================================================

    public function test_the_coach_list_shows_assigned_students_with_this_weeks_progress(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz');
        $cagri = $this->ogrenci('Çağrı Öztürk', ['grade' => '10', 'field' => null]);
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ayse, $cagri);

        $this->madde($ayse, '2026-09-29', ['status' => 'done']);
        $this->madde($ayse, '2026-10-01');
        // Gecen haftanin maddesi bu haftanin sayimina girmemeli.
        $this->madde($ayse, '2026-09-22');

        $yanit = $this->actingAs($koc)->get(route('coach.plan.index'))->assertOk();

        $yanit->assertSee('Öğrenciler (2)')
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('Çağrı Öztürk')
            ->assertDontSee('Gökhan Işık')
            ->assertSee('1 / 2')
            ->assertSee('Plan yok')
            ->assertSee(route('coach.plan.show', $ayse), false)
            ->assertSee(route('coach.plan.show', $cagri), false)
            ->assertDontSee(route('coach.plan.show', $baskasi), false);
    }

    public function test_a_coach_without_students_sees_the_empty_state(): void
    {
        $koc = $this->koc();

        $this->actingAs($koc)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('Henüz öğrenciniz yok');
    }

    public function test_an_admin_sees_every_student_in_the_coach_list(): void
    {
        $this->ogrenci('Ayşe Yılmaz');
        $this->ogrenci('Gökhan Işık');
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('Gökhan Işık');
    }

    public function test_non_coach_roles_are_refused_from_every_coach_page(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
        $ogretmen = User::factory()->create(['role' => Role::Teacher->value]);
        $gorevli = User::factory()->create(['role' => Role::Staff->value]);

        $sayfalar = [
            route('coach.plan.index'),
            route('coach.plan.show', $ogrenci),
            route('coach.notes.index', $ogrenci),
            route('coach.topics.index', $ogrenci),
            route('coach.report', $ogrenci),
        ];

        foreach ([$ogrenci, $veli, $ogretmen, $gorevli] as $kisi) {
            foreach ($sayfalar as $sayfa) {
                $this->actingAs($kisi)->get($sayfa)->assertForbidden();
            }
        }

        // Ogrenci kendi planina madde yazamaz, kendi hakkinda not dusemez.
        $this->actingAs($ogrenci)->post(route('coach.plan.store', $ogrenci), [
            'plan_date' => '2026-09-30', 'title' => 'Kendime görev',
        ])->assertForbidden();
        $this->actingAs($ogrenci)->post(route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => 'Kendim hakkında'])
            ->assertForbidden();
        $this->actingAs($veli)->post(route('coach.topics.store', $ogrenci), ['topic' => 'Veli ekledi'])
            ->assertForbidden();
        $this->assertDatabaseCount('study_plan_items', 0);
        $this->assertDatabaseCount('coach_notes', 0);
        $this->assertDatabaseCount('weak_topics', 0);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $ogrenci = $this->ogrenci();

        $this->get(route('coach.plan.index'))->assertRedirect(route('login'));
        $this->get(route('coach.plan.show', $ogrenci))->assertRedirect(route('login'));
        $this->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30', 'title' => 'x'])
            ->assertRedirect(route('login'));
    }

    // =========================================================================
    // Takvim (coach.plan.show) ve hafta gezinmesi
    // =========================================================================

    public function test_the_calendar_shows_items_commitments_logs_and_only_relevant_subjects(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $fizik = $this->ders('ayt_fizik');

        $this->madde($ogrenci, '2026-09-30', [
            'subject_id' => $fizik->id,
            'title' => 'Tork ve Denge soru bankası',
            'starts_at' => '09:00',
            'duration_minutes' => 45,
        ]);
        StudentCommitment::create([
            'student_id' => $ogrenci->id, 'kind' => 'okul', 'title' => 'Kadıköy Anadolu Lisesi',
            'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '15:00',
        ]);
        StudyLog::create([
            'student_id' => $ogrenci->id, 'subject_id' => $fizik->id,
            'study_session_id' => $this->oturum($ogrenci, '2026-09-29', 120)->id,
            'amount' => 40, 'unit' => 'soru', 'note' => 'Şıkları eledim',
        ]);

        $yanit = $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertOk();

        $yanit->assertSee('Ayşe Yılmaz')
            ->assertSee('28 Eylül – 4 Ekim 2026')
            ->assertSee('Tork ve Denge soru bankası')
            ->assertSee('09:00 · 45 dk')
            ->assertSee('Okul · Kadıköy Anadolu Lisesi')
            ->assertSee('08:00–15:00')
            ->assertSee('AYT Fizik · 40 soru')
            ->assertSee('Şıkları eledim')
            // Sayisal 12. sinif: sozel ders listede olmamali.
            ->assertSee('<option value="' . $fizik->id . '"', false)
            ->assertDontSee('<option value="' . $this->ders('tarih_2')->id . '"', false)
            // Tasima ve silme formlari.
            ->assertSee(route('coach.plan.move', StudyPlanItem::first()), false)
            ->assertSee(route('coach.plan.destroy', StudyPlanItem::first()), false)
            // Sekmeler ve sabit program formu.
            ->assertSee(route('coach.notes.index', $ogrenci), false)
            ->assertSee(route('coach.topics.index', $ogrenci), false)
            ->assertSee(route('coach.report', $ogrenci), false)
            ->assertSee(route('coach.commitments.store', $ogrenci), false);
    }

    public function test_an_empty_calendar_renders_for_a_student_without_grade_or_data(): void
    {
        $ogrenci = User::factory()->student()->create(['name' => 'Şule Ağaoğlu']);
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))
            ->assertOk()
            ->assertSee('Şule Ağaoğlu')
            ->assertSee('Henüz kayıt yok.')
            // Sinifi girilmemis ogrenci: tum dersler listelenir.
            ->assertSee('<option value="' . $this->ders('tarih_2')->id . '"', false);
    }

    public function test_week_navigation_moves_one_week_at_a_time(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Bu haftanın maddesi']);
        $this->madde($ogrenci, '2026-10-07', ['title' => 'Gelecek haftanın maddesi']);

        $buHafta = $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertOk();
        $buHafta->assertSee('Bu haftanın maddesi')
            ->assertDontSee('Gelecek haftanın maddesi')
            ->assertSee($this->planSayfasi($ogrenci, ['hafta' => '2026-09-21']), false)
            ->assertSee($this->planSayfasi($ogrenci, ['hafta' => '2026-10-05']), false);

        // Haftanin herhangi bir gunu o haftayi acar.
        $gelecek = $this->actingAs($koc)->get($this->planSayfasi($ogrenci, ['hafta' => '2026-10-08']))->assertOk();
        $gelecek->assertSee('5 Ekim – 11 Ekim 2026')
            ->assertSee('Gelecek haftanın maddesi')
            ->assertDontSee('Bu haftanın maddesi')
            ->assertSee($this->planSayfasi($ogrenci, ['hafta' => '2026-09-28']), false)
            ->assertSee($this->planSayfasi($ogrenci, ['hafta' => '2026-10-12']), false)
            // Baska haftada ekleme formunun gunu o haftanin pazartesisi.
            ->assertSee('value="2026-10-05" required', false);
    }

    public function test_a_junk_week_parameter_falls_back_to_the_current_week(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        foreach (['abc', '2026-13-45', '   '] as $cop) {
            $this->actingAs($koc)->get($this->planSayfasi($ogrenci, ['hafta' => $cop]))
                ->assertOk()
                ->assertSee('28 Eylül – 4 Ekim 2026');
        }

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci) . '?hafta[]=x')
            ->assertOk()
            ->assertSee('28 Eylül – 4 Ekim 2026');
    }

    /** Kafe saatiyle sali 00:30 = UTC'de hala pazartesi 21:30. */
    public function test_just_after_local_midnight_today_is_the_local_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30', config('kafe.timezone')));
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))
            ->assertOk()
            ->assertSee('28 Eylül – 4 Ekim 2026')
            ->assertSee('value="2026-09-29" required', false)
            ->assertSeeInOrder(['day-col today', 'Sal 29'], false);

        // Gece yazilan not o yerel gunun tarihini tasir.
        $this->actingAs($koc)->post(route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => 'Gece notu'])->assertRedirect();
        $this->actingAs($koc)->get(route('coach.notes.index', $ogrenci))->assertSee('29.09.2026')->assertDontSee('28.09.2026');
    }

    public function test_a_graduate_in_equal_weight_sees_only_their_subjects(): void
    {
        $ogrenci = $this->ogrenci('Elif Çelik', ['grade' => 'mezun', 'field' => 'ea']);
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))
            ->assertOk()
            ->assertSee('<option value="' . $this->ders('edebiyat')->id . '"', false)
            ->assertSee('<option value="' . $this->ders('ayt_matematik')->id . '"', false)
            ->assertDontSee('<option value="' . $this->ders('ayt_fizik')->id . '"', false)
            ->assertDontSee('<option value="' . $this->ders('okul_matematik')->id . '"', false);
    }

    /**
     * Pazar gunune konan madde takvimde gorunmeli.
     *
     * 'date' cast'i degeri "2026-10-04 00:00:00" diye yaziyor; WeekPlan
     * whereBetween('plan_date', ['2026-09-28', '2026-10-04']) ile okuyor.
     * SQLite'ta metin karsilastirmasi "2026-10-04 00:00:00" > "2026-10-04"
     * oldugu icin haftanin son gunu dusuyor.
     */
    public function test_an_item_on_sunday_shows_on_the_calendar(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)
            ->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-10-04',
                'title' => 'Pazar deneme tekrarı',
            ])->assertRedirect($this->planSayfasi($ogrenci));

        $this->assertDatabaseHas('study_plan_items', ['title' => 'Pazar deneme tekrarı']);

        $gunler = \App\Support\WeekPlan::for($ogrenci, self::HAFTA);
        $this->assertCount(1, $gunler[6]['items']);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))
            ->assertOk()
            ->assertSee('Pazar deneme tekrarı');
    }

    public function test_a_coach_cannot_open_an_unassigned_students_calendar(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get($this->planSayfasi($baskasi))->assertForbidden();

        // Ogrenci olmayan kullanici 404; olmayan kimlik 404.
        $veli = User::factory()->parent()->create();
        $this->actingAs($koc)->get($this->planSayfasi($veli))->assertNotFound();
        $this->actingAs($koc)->get(route('coach.plan.show', 999999))->assertNotFound();
    }

    // =========================================================================
    // Plana ekle (coach.plan.store)
    // =========================================================================

    public function test_a_coach_adds_a_subject_topic_item_with_time_and_duration(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $mat = $this->ders('tyt_matematik');
        $konu = $this->konu('tyt_matematik', 'Bölme ve Bölünebilme');

        $this->actingAs($koc)
            ->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-09-30',
                'subject_id' => $mat->id,
                'subject_topic_id' => $konu->id,
                'title' => '',
                'starts_at' => '16:30',
                'duration_minutes' => 60,
            ])
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHas('success', 'Plana eklendi.')
            ->assertSessionHasNoErrors();

        $madde = StudyPlanItem::sole();
        $this->assertSame($ogrenci->id, $madde->student_id);
        $this->assertSame($mat->id, $madde->subject_id);
        $this->assertSame($konu->id, $madde->subject_topic_id);
        $this->assertSame('Bölme ve Bölünebilme', $madde->title);
        $this->assertSame('2026-09-30', $madde->plan_date->toDateString());
        $this->assertSame(self::HAFTA, $madde->week_start->toDateString());
        $this->assertSame('16:30', $madde->starts_at);
        $this->assertSame(60, (int) $madde->duration_minutes);
        $this->assertSame($koc->id, $madde->created_by);
        $this->assertSame('open', $madde->status);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))
            ->assertSee('TYT Matematik')
            ->assertSee('Bölme ve Bölünebilme')
            ->assertSee('16:30 · 60 dk');

        // Ogrenci ayni maddeyi kendi planinda gorur.
        $this->actingAs($ogrenci)->get(route('user.plan'))
            ->assertOk()
            ->assertSee('Bölme ve Bölünebilme');
    }

    public function test_a_free_turkish_note_becomes_the_title_without_a_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $not = 'Paragraf: 40 soru, şıkları gözden geçir — ığüşöç İĞÜŞÖÇ';

        $this->actingAs($koc)
            ->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-10-01',
                'subject_id' => '',
                'subject_topic_id' => '',
                'title' => $not,
                'starts_at' => '',
                'duration_minutes' => '',
            ])
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHasNoErrors();

        $madde = StudyPlanItem::sole();
        $this->assertSame($not, $madde->title);
        $this->assertNull($madde->subject_id);
        $this->assertNull($madde->starts_at);
        $this->assertNull($madde->duration_minutes);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertSee($not);
    }

    public function test_a_written_note_wins_over_the_topic_name(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $konu = $this->konu('tyt_matematik', 'Rasyonel Sayılar');

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-10-02',
                'subject_id' => $konu->subject_id,
                'subject_topic_id' => $konu->id,
                'title' => 'Rasyonel sayılar tekrar + 30 soru',
            ])->assertSessionHasNoErrors();

        $madde = StudyPlanItem::sole();
        $this->assertSame('Rasyonel sayılar tekrar + 30 soru', $madde->title);
        $this->assertSame($konu->id, $madde->subject_topic_id);
    }

    public function test_the_week_start_follows_the_chosen_day_across_week_and_month_edges(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        // Pazar: bu haftanin son gunu. Pazartesi: yeni hafta. 1 Ekim persembe:
        // ay degisse de hafta 28 Eylul.
        foreach (['2026-10-04' => self::HAFTA, '2026-10-05' => '2026-10-05', '2026-10-01' => self::HAFTA] as $gun => $beklenen) {
            $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
                ->post(route('coach.plan.store', $ogrenci), ['plan_date' => $gun, 'title' => "Madde {$gun}"])
                ->assertSessionHasNoErrors();

            $madde = StudyPlanItem::where('title', "Madde {$gun}")->sole();
            $this->assertSame($beklenen, $madde->week_start->toDateString(), $gun);
            $this->assertSame('week', $madde->period->value);
        }
    }

    public function test_the_add_form_refuses_bad_input_without_creating_anything(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $mat = $this->ders('tyt_matematik');
        $fizikKonusu = SubjectTopic::where('subject_id', $this->ders('tyt_fizik')->id)->first();

        $vakalar = [
            'ders ya da not yok' => [['plan_date' => '2026-09-30'], 'subject_id'],
            'gun yok' => [['title' => 'Paragraf'], 'plan_date'],
            'gecersiz gun' => [['plan_date' => '2026-02-30', 'title' => 'Paragraf'], 'plan_date'],
            'yanlis bicim gun' => [['plan_date' => '30.09.2026', 'title' => 'Paragraf'], 'plan_date'],
            'baska dersin konusu' => [['plan_date' => '2026-09-30', 'subject_id' => $mat->id, 'subject_topic_id' => $fizikKonusu->id], 'subject_topic_id'],
            'olmayan ders' => [['plan_date' => '2026-09-30', 'subject_id' => 999999], 'subject_id'],
            'gecersiz saat' => [['plan_date' => '2026-09-30', 'title' => 'Paragraf', 'starts_at' => '25:00'], 'starts_at'],
            'cok kisa sure' => [['plan_date' => '2026-09-30', 'title' => 'Paragraf', 'duration_minutes' => 3], 'duration_minutes'],
            'cok uzun sure' => [['plan_date' => '2026-09-30', 'title' => 'Paragraf', 'duration_minutes' => 721], 'duration_minutes'],
            'uzun not' => [['plan_date' => '2026-09-30', 'title' => str_repeat('ş', 151)], 'title'],
        ];

        foreach ($vakalar as $ad => [$veri, $alan]) {
            $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
                ->post(route('coach.plan.store', $ogrenci), $veri)
                ->assertRedirect($this->planSayfasi($ogrenci))
                ->assertSessionHasErrors($alan);
        }

        $this->assertDatabaseCount('study_plan_items', 0);

        // Turkce hata mesaji.
        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30'])
            ->assertSessionHasErrors(['subject_id' => 'Bir ders seçin ya da not yazın.']);
    }

    public function test_a_coach_cannot_add_for_an_unassigned_student_even_with_a_broken_form(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->post(route('coach.plan.store', $baskasi), [
            'plan_date' => '2026-09-30', 'title' => 'Sızma denemesi',
        ])->assertForbidden();

        // Yetki once: bozuk form da 403 almali, dogrulama hatasi degil.
        $this->actingAs($koc)->post(route('coach.plan.store', $baskasi), [])->assertForbidden();

        $this->assertDatabaseCount('study_plan_items', 0);
    }

    public function test_an_admin_adds_to_any_student_without_assignment(): void
    {
        $ogrenci = $this->ogrenci();
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30', 'title' => 'Yönetici notu'])
            ->assertRedirect($this->planSayfasi($ogrenci));

        $this->assertDatabaseHas('study_plan_items', ['student_id' => $ogrenci->id, 'title' => 'Yönetici notu', 'created_by' => $yonetici->id]);
    }

    // =========================================================================
    // Tasi (coach.plan.move) ve sil (coach.plan.destroy)
    // =========================================================================

    public function test_a_coach_moves_an_item_to_another_day_and_week(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $madde = $this->madde($ogrenci, '2026-09-30', ['title' => 'Türev tekrarı']);

        // Ayni hafta icinde.
        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->patch(route('coach.plan.move', $madde), ['plan_date' => '2026-10-02'])
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHas('success', 'Taşındı.');

        $madde->refresh();
        $this->assertSame('2026-10-02', $madde->plan_date->toDateString());
        $this->assertSame(self::HAFTA, $madde->week_start->toDateString());

        // Gelecek haftaya: week_start de degismeli ki ilerleme dogru haftaya dussun.
        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->patch(route('coach.plan.move', $madde), ['plan_date' => '2026-10-06'])
            ->assertRedirect();

        $madde->refresh();
        $this->assertSame('2026-10-06', $madde->plan_date->toDateString());
        $this->assertSame('2026-10-05', $madde->week_start->toDateString());

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertDontSee('Türev tekrarı');
        $this->actingAs($koc)->get($this->planSayfasi($ogrenci, ['hafta' => '2026-10-06']))->assertSee('Türev tekrarı');
    }

    public function test_moving_needs_a_real_date(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $madde = $this->madde($ogrenci, '2026-09-30');

        foreach (['', 'yarın', '2026-02-30', '02.10.2026'] as $cop) {
            $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
                ->patch(route('coach.plan.move', $madde), ['plan_date' => $cop])
                ->assertSessionHasErrors('plan_date');
        }

        $this->assertSame('2026-09-30', $madde->fresh()->plan_date->toDateString());
    }

    public function test_a_coach_deletes_an_item(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $madde = $this->madde($ogrenci, '2026-09-30');

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->delete(route('coach.plan.destroy', $madde))
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHas('success', 'Plandan silindi.');

        $this->assertModelMissing($madde);

        // Ikinci kez silmek (cift dokunus) 404, 500 degil.
        $this->actingAs($koc)->delete(route('coach.plan.destroy', $madde))->assertNotFound();
    }

    public function test_a_coach_cannot_move_or_delete_an_unassigned_students_item(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);
        $madde = $this->madde($baskasi, '2026-09-30');

        $this->actingAs($koc)->patch(route('coach.plan.move', $madde), ['plan_date' => '2026-10-02'])->assertForbidden();
        $this->actingAs($koc)->delete(route('coach.plan.destroy', $madde))->assertForbidden();

        $this->assertSame('2026-09-30', $madde->fresh()->plan_date->toDateString());
    }

    // =========================================================================
    // Haftalik sabit program (coach.commitments.*)
    // =========================================================================

    public function test_a_coach_adds_a_weekly_program_one_row_per_day(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'dershane',
                'commitment_title' => 'Limit Dershanesi Kadıköy',
                // Cift gonderilen gun tek satir olmali.
                'weekdays' => ['1', '3', '5', '3'],
                'commitment_starts_at' => '17:00',
                'commitment_ends_at' => '20:30',
            ])
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHas('success', 'Programa eklendi.')
            ->assertSessionHasNoErrors();

        $satirlar = StudentCommitment::where('student_id', $ogrenci->id)->orderBy('weekday')->get();
        $this->assertSame([1, 3, 5], $satirlar->pluck('weekday')->all());
        $this->assertSame(['Limit Dershanesi Kadıköy'], $satirlar->pluck('title')->unique()->values()->all());
        $this->assertSame($koc->id, $satirlar->first()->created_by);

        $yanit = $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertOk();
        $yanit->assertSee('Dershane · Limit Dershanesi Kadıköy')
            ->assertSee('17:00–20:30')
            ->assertSee(route('coach.commitments.destroy', $satirlar->first()), false);

        // Takvimde pazartesi, carsamba, cuma dolu; sali bos.
        $gunler = \App\Support\WeekPlan::for($ogrenci, self::HAFTA);
        $this->assertCount(1, $gunler[0]['commitments']);
        $this->assertCount(0, $gunler[1]['commitments']);
        $this->assertCount(1, $gunler[2]['commitments']);
        $this->assertCount(1, $gunler[4]['commitments']);
    }

    public function test_the_program_title_is_optional(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'okul', 'commitment_title' => '', 'weekdays' => [1, 2, 3, 4, 5],
                'commitment_starts_at' => '08:00', 'commitment_ends_at' => '15:00',
            ])->assertSessionHasNoErrors();

        $this->assertSame(5, StudentCommitment::whereNull('title')->count());
    }

    public function test_the_program_form_refuses_bad_input(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $gecerli = ['kind' => 'okul', 'weekdays' => [1], 'commitment_starts_at' => '08:00', 'commitment_ends_at' => '15:00'];

        $vakalar = [
            'gun yok' => [['weekdays' => []], 'weekdays'],
            'bitis once' => [['commitment_starts_at' => '15:00', 'commitment_ends_at' => '08:00'], 'commitment_ends_at'],
            'esit saat' => [['commitment_starts_at' => '15:00', 'commitment_ends_at' => '15:00'], 'commitment_ends_at'],
            'bilinmeyen tur' => [['kind' => 'kurs'], 'kind'],
            'gecersiz gun' => [['weekdays' => [8]], 'weekdays.0'],
            'gecersiz saat' => [['commitment_starts_at' => '8'], 'commitment_starts_at'],
        ];

        foreach ($vakalar as $ad => [$degisim, $alan]) {
            $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
                ->post(route('coach.commitments.store', $ogrenci), array_merge($gecerli, $degisim))
                ->assertSessionHasErrors($alan);
        }

        $this->assertDatabaseCount('student_commitments', 0);

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), array_merge($gecerli, ['commitment_starts_at' => '15:00', 'commitment_ends_at' => '08:00']))
            ->assertSessionHasErrors(['commitment_ends_at' => 'Bitiş başlangıçtan sonra olmalı.']);
    }

    /**
     * Plan formu ile sabit program formu ayni sayfada ve ikisi de
     * "starts_at" adini kullaniyordu. Program formundaki bir hata, katli
     * "Plana ekle" formunu aciyor ve Saat alanina programin saatini
     * yaziyordu; koc o formdan madde eklerse yanlis saat sessizce kaydolurdu.
     * Program alanlari artik commitment_ onekli.
     */
    public function test_a_program_form_error_does_not_leak_into_the_plan_form(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $sayfa = $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->followingRedirects()
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'okul', 'weekdays' => [1],
                'commitment_starts_at' => '17:45', 'commitment_ends_at' => '08:00',
            ])
            ->assertOk()
            ->assertSee('Bitiş başlangıçtan sonra olmalı.');

        // Plan formu katli kalmali ve Saat alani bos olmali.
        $html = $sayfa->getContent();
        $sayfa->assertDontSee('id="planaEkle" open', false);
        $this->assertMatchesRegularExpression('/<input[^>]*name="starts_at"[^>]*value=""/', $html);

        // Program formu kendi girdisini geri alir ve hatasini kendisi gosterir.
        $this->assertMatchesRegularExpression('/<input[^>]*name="commitment_starts_at"[^>]*value="17:45"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="commitment_ends_at"[^>]*is-invalid[^>]*value="08:00"/', $html);
    }

    public function test_a_coach_removes_a_single_day_of_the_program(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $pzt = StudentCommitment::create(['student_id' => $ogrenci->id, 'kind' => 'okul', 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '15:00']);
        $cum = StudentCommitment::create(['student_id' => $ogrenci->id, 'kind' => 'okul', 'weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '12:30']);

        $this->actingAs($koc)->from($this->planSayfasi($ogrenci))
            ->delete(route('coach.commitments.destroy', $cum))
            ->assertRedirect($this->planSayfasi($ogrenci))
            ->assertSessionHas('success', 'Programdan silindi.');

        $this->assertModelMissing($cum);
        $this->assertModelExists($pzt);
    }

    public function test_a_coach_cannot_touch_an_unassigned_students_program(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);
        $satir = StudentCommitment::create(['student_id' => $baskasi->id, 'kind' => 'okul', 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '15:00']);

        $this->actingAs($koc)->post(route('coach.commitments.store', $baskasi), [
            'kind' => 'okul', 'weekdays' => [2], 'commitment_starts_at' => '08:00', 'commitment_ends_at' => '15:00',
        ])->assertForbidden();
        $this->actingAs($koc)->delete(route('coach.commitments.destroy', $satir))->assertForbidden();

        $this->assertModelExists($satir);
        $this->assertSame(1, StudentCommitment::count());
    }

    // =========================================================================
    // Notlar (coach.notes.*)
    // =========================================================================

    public function test_the_notes_page_lists_shared_and_private_records(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $eskiKoc = User::factory()->create(['role' => Role::Coach->value]);

        CoachNote::create([
            'student_id' => $ogrenci->id, 'created_by' => $koc->id, 'kind' => 'note',
            'visibility' => 'parent', 'body' => 'Matematikte tempo düştü, hafta içi tekrar ekledik.',
        ]);
        CoachNote::create([
            'student_id' => $ogrenci->id, 'created_by' => $eskiKoc->id, 'kind' => 'meeting',
            'visibility' => 'private', 'body' => 'Anneyle görüştük; sınav kaygısı var.', 'occurred_on' => '2026-09-25',
        ]);
        $eskiKoc->delete();

        $this->actingAs($koc)->get(route('coach.notes.index', $ogrenci))
            ->assertOk()
            ->assertSee('Kayıtlar (2)')
            ->assertSee('Matematikte tempo düştü, hafta içi tekrar ekledik.')
            ->assertSee('Anneyle görüştük; sınav kaygısı var.')
            ->assertSee('Veliyle paylaşıldı')
            ->assertSee('Yalnız koç ve yönetici')
            ->assertSee('Veli görüşmesi')
            ->assertSee('25.09.2026')
            ->assertSee('Koç Şükrü Güneş')
            ->assertSee('Silinmiş kullanıcı')
            ->assertSee(route('coach.notes.store', $ogrenci), false);
    }

    public function test_the_notes_page_has_an_empty_state(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.notes.index', $ogrenci))
            ->assertOk()
            ->assertSee('Kayıtlar (0)')
            ->assertSee('Henüz kayıt yok.');
    }

    public function test_a_note_is_shared_with_the_parent_by_default(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
        $metin = 'Ayşe bu hafta çok iyiydi; paragraf hızı arttı. İyi gidiyor!';

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->post(route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => "  {$metin}  "])
            ->assertRedirect(route('coach.notes.index', $ogrenci))
            ->assertSessionHas('success', 'Not kaydedildi.');

        $not = CoachNote::sole();
        $this->assertSame($metin, $not->body);
        $this->assertSame('parent', $not->visibility->value);
        $this->assertNull($not->occurred_on);
        $this->assertSame($koc->id, $not->created_by);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee($metin);
    }

    public function test_a_private_meeting_record_is_kept_from_the_parent_and_student(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
        $metin = 'Babayla telefonda konuştuk: ev ortamı gergin, dikkat.';

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'meeting', 'visibility' => 'private', 'body' => $metin, 'occurred_on' => '2026-09-27',
            ])
            ->assertRedirect(route('coach.notes.index', $ogrenci))
            ->assertSessionHas('success', 'Veli görüşmesi kaydedildi.');

        $not = CoachNote::sole();
        $this->assertSame('meeting', $not->kind->value);
        $this->assertSame('private', $not->visibility->value);
        $this->assertSame('2026-09-27', $not->occurred_on->toDateString());

        $this->actingAs($koc)->get(route('coach.notes.index', $ogrenci))->assertSee('27.09.2026')->assertSee($metin);
        $this->actingAs($veli)->get(route('parent.student', $ogrenci))->assertOk()->assertDontSee($metin);
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()->assertDontSee($metin);
    }

    public function test_the_note_form_refuses_bad_input(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $sayfa = route('coach.notes.index', $ogrenci);

        $vakalar = [
            'bos' => [['kind' => 'note', 'body' => ''], 'body'],
            'bosluk' => [['kind' => 'note', 'body' => "   \n  "], 'body'],
            'uzun' => [['kind' => 'note', 'body' => str_repeat('ğ', 2001)], 'body'],
            'bilinmeyen tur' => [['kind' => 'sohbet', 'body' => 'Merhaba'], 'kind'],
            'bilinmeyen gorunurluk' => [['kind' => 'note', 'visibility' => 'herkes', 'body' => 'Merhaba'], 'visibility'],
            'tarihsiz gorusme' => [['kind' => 'meeting', 'body' => 'Görüştük'], 'occurred_on'],
            'gecersiz tarih' => [['kind' => 'meeting', 'body' => 'Görüştük', 'occurred_on' => 'dün'], 'occurred_on'],
        ];

        foreach ($vakalar as $ad => [$veri, $alan]) {
            $this->actingAs($koc)->from($sayfa)
                ->post(route('coach.notes.store', $ogrenci), $veri)
                ->assertRedirect($sayfa)
                ->assertSessionHasErrors($alan);
        }

        $this->assertDatabaseCount('coach_notes', 0);

        $this->actingAs($koc)->from($sayfa)
            ->post(route('coach.notes.store', $ogrenci), ['kind' => 'meeting', 'body' => 'Görüştük'])
            ->assertSessionHasErrors(['occurred_on' => 'Görüşmenin hangi gün yapıldığını yaz.']);
    }

    public function test_a_coach_deletes_a_note(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $not = CoachNote::create(['student_id' => $ogrenci->id, 'created_by' => $koc->id, 'body' => 'Silinecek not']);

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->delete(route('coach.notes.destroy', $not))
            ->assertRedirect(route('coach.notes.index', $ogrenci))
            ->assertSessionHas('success', 'Kayıt silindi.');

        $this->assertModelMissing($not);
    }

    public function test_a_coach_cannot_read_write_or_delete_an_unassigned_students_notes(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);
        $not = CoachNote::create(['student_id' => $baskasi->id, 'body' => 'Başkasının notu', 'visibility' => 'private']);

        $this->actingAs($koc)->get(route('coach.notes.index', $baskasi))->assertForbidden();
        $this->actingAs($koc)->post(route('coach.notes.store', $baskasi), ['kind' => 'note', 'body' => 'Sızma'])->assertForbidden();
        $this->actingAs($koc)->post(route('coach.notes.store', $baskasi), [])->assertForbidden();
        $this->actingAs($koc)->delete(route('coach.notes.destroy', $not))->assertForbidden();

        $this->assertModelExists($not);
        $this->assertSame(1, CoachNote::count());
    }

    // =========================================================================
    // Zayif konular (coach.topics.*)
    // =========================================================================

    public function test_the_topics_page_lists_open_and_closed_topics(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $fizik = $this->ders('ayt_fizik');

        WeakTopic::create(['student_id' => $ogrenci->id, 'subject_id' => $fizik->id, 'topic' => 'Tork ve denge', 'created_by' => $koc->id]);
        WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Deneme stresi', 'created_by' => $koc->id]);
        WeakTopic::create([
            'student_id' => $ogrenci->id, 'topic' => 'Üslü sayılar', 'status' => 'closed',
            // Kafe saatiyle 27 Eylul 01:30 = UTC'de 26 Eylul 22:30.
            'closed_at' => Carbon::parse('2026-09-27 01:30', config('kafe.timezone'))->utc(),
        ]);

        $yanit = $this->actingAs($koc)->get(route('coach.topics.index', $ogrenci))->assertOk();

        $yanit->assertSee('Konular (3)')
            ->assertSee('Tork ve denge')
            ->assertSee('AYT Fizik')
            ->assertSee('Deneme stresi')
            ->assertSee('Genel')
            ->assertSee('Üslü sayılar')
            ->assertSee('27.09.2026 tarihinde kapatıldı')
            ->assertSee('Yeniden aç')
            ->assertSee('Plana ekle');
    }

    /**
     * orderBy('status') alfabetik: 'closed' < 'open'. Kapanmis konular
     * (gecmis) ustte, uzerinde islem yapilacak acik konular altta kaliyor;
     * liste aylar icinde uzadikca acik konular gomulur.
     */
    public function test_open_topics_are_listed_before_closed_ones(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        // Kapanmis konu bir acik konudan YENI: yalnizca tarihe gore siralama
        // onu ustte birakir. Saat donuk oldugu icin araya zaman konuyor;
        // esit created_at'te SQLite ekleme sirasina dusup testi yanlislikla
        // gecirirdi.
        WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Açık konu: türev']);
        $this->travel(1)->hours();
        WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Kapanmış konu: limit', 'status' => 'closed', 'closed_at' => now()]);
        $this->travel(1)->hours();
        WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Açık konu: yeni']);

        // Acik grubun icinde de en yeni ustte.
        $this->actingAs($koc)->get(route('coach.topics.index', $ogrenci))
            ->assertOk()
            ->assertSeeInOrder(['Açık konu: yeni', 'Açık konu: türev', 'Kapanmış konu: limit']);
    }

    public function test_the_topics_page_has_an_empty_state(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.topics.index', $ogrenci))
            ->assertOk()
            ->assertSee('Henüz konu eklenmemiş.');
    }

    public function test_a_coach_adds_a_topic_with_turkish_text(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $mat = $this->ders('ayt_matematik');

        $this->actingAs($koc)->from(route('coach.topics.index', $ogrenci))
            ->post(route('coach.topics.store', $ogrenci), ['topic' => '  Türev – zincir kuralı (ığüşöç)  ', 'subject_id' => $mat->id])
            ->assertRedirect(route('coach.topics.index', $ogrenci))
            ->assertSessionHas('success', 'Konu eklendi.');

        $this->actingAs($koc)->from(route('coach.topics.index', $ogrenci))
            ->post(route('coach.topics.store', $ogrenci), ['topic' => 'Soru çözme hızı', 'subject_id' => ''])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('weak_topics', [
            'student_id' => $ogrenci->id, 'subject_id' => $mat->id,
            'topic' => 'Türev – zincir kuralı (ığüşöç)', 'status' => 'open', 'created_by' => $koc->id,
        ]);
        $this->assertDatabaseHas('weak_topics', ['topic' => 'Soru çözme hızı', 'subject_id' => null]);

        // Ogrenci acik konulari kendi panelinde gorur.
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()->assertSee('Soru çözme hızı');
    }

    public function test_the_topic_form_refuses_bad_input(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $sayfa = route('coach.topics.index', $ogrenci);

        foreach ([
            [['topic' => ''], 'topic'],
            [['topic' => '    '], 'topic'],
            [['topic' => str_repeat('ı', 151)], 'topic'],
            [['topic' => 'Türev', 'subject_id' => 999999], 'subject_id'],
        ] as [$veri, $alan]) {
            $this->actingAs($koc)->from($sayfa)
                ->post(route('coach.topics.store', $ogrenci), $veri)
                ->assertRedirect($sayfa)
                ->assertSessionHasErrors($alan);
        }

        $this->assertDatabaseCount('weak_topics', 0);
    }

    public function test_a_coach_closes_and_reopens_a_topic(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $konu = WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Limit ve süreklilik', 'created_by' => $koc->id]);
        $sayfa = route('coach.topics.index', $ogrenci);

        $this->actingAs($koc)->from($sayfa)->post(route('coach.topics.close', $konu))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Konu kapatıldı.');

        $konu->refresh();
        $this->assertSame('closed', $konu->status);
        $ilkKapanis = $konu->closed_at;
        $this->assertNotNull($ilkKapanis);

        // Cift dokunus kapanma anini kaydirmaz.
        $this->travel(10)->minutes();
        $this->actingAs($koc)->from($sayfa)->post(route('coach.topics.close', $konu))->assertRedirect($sayfa);
        $this->assertTrue($ilkKapanis->equalTo($konu->fresh()->closed_at));

        // Kapali konu ogrencinin listesinden cikar.
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()
            ->assertDontSee('<strong>Limit ve süreklilik</strong>', false);

        $this->actingAs($koc)->from($sayfa)->post(route('coach.topics.reopen', $konu))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Konu yeniden açıldı.');

        $konu->refresh();
        $this->assertSame('open', $konu->status);
        $this->assertNull($konu->closed_at);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()
            ->assertSee('<strong>Limit ve süreklilik</strong>', false);
    }

    public function test_a_topic_goes_to_todays_plan(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $fizik = $this->ders('ayt_fizik');
        $konu = WeakTopic::create(['student_id' => $ogrenci->id, 'subject_id' => $fizik->id, 'topic' => 'Elektrik alan ve potansiyel']);
        $sayfa = route('coach.topics.index', $ogrenci);

        $this->actingAs($koc)->from($sayfa)->post(route('coach.topics.plan', $konu))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Konu bu haftanın planına eklendi.');

        $madde = StudyPlanItem::sole();
        $this->assertSame($ogrenci->id, $madde->student_id);
        $this->assertSame($fizik->id, $madde->subject_id);
        $this->assertSame('Elektrik alan ve potansiyel', $madde->title);
        $this->assertSame('2026-09-29', $madde->plan_date->toDateString());
        $this->assertSame(self::HAFTA, $madde->week_start->toDateString());
        $this->assertSame($koc->id, $madde->created_by);

        // Konu acik kalir (plana gitmek halletmek demek degil).
        $this->assertSame('open', $konu->fresh()->status);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertSee('Elektrik alan ve potansiyel');
    }

    /** Kafe saatiyle pazartesi 00:30 = UTC'de hala pazar 21:30. */
    public function test_a_topic_sent_to_plan_just_after_local_midnight_lands_on_the_local_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 00:30', config('kafe.timezone')));
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $konu = WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Olasılık']);

        $this->actingAs($koc)->post(route('coach.topics.plan', $konu))->assertRedirect();

        $madde = StudyPlanItem::sole();
        $this->assertSame('2026-10-05', $madde->plan_date->toDateString());
        $this->assertSame('2026-10-05', $madde->week_start->toDateString());
    }

    public function test_a_coach_deletes_a_topic(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $konu = WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Silinecek konu']);
        $sayfa = route('coach.topics.index', $ogrenci);

        $this->actingAs($koc)->from($sayfa)->delete(route('coach.topics.destroy', $konu))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Konu silindi.');

        $this->assertModelMissing($konu);
    }

    public function test_a_coach_cannot_touch_an_unassigned_students_topics(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);
        $konu = WeakTopic::create(['student_id' => $baskasi->id, 'topic' => 'Başkasının konusu']);

        $this->actingAs($koc)->get(route('coach.topics.index', $baskasi))->assertForbidden();
        $this->actingAs($koc)->post(route('coach.topics.store', $baskasi), ['topic' => 'Sızma'])->assertForbidden();
        $this->actingAs($koc)->post(route('coach.topics.close', $konu))->assertForbidden();
        $this->actingAs($koc)->post(route('coach.topics.reopen', $konu))->assertForbidden();
        $this->actingAs($koc)->post(route('coach.topics.plan', $konu))->assertForbidden();
        $this->actingAs($koc)->delete(route('coach.topics.destroy', $konu))->assertForbidden();

        $this->assertSame('open', $konu->fresh()->status);
        $this->assertSame(1, WeakTopic::count());
        $this->assertDatabaseCount('study_plan_items', 0);
    }

    // =========================================================================
    // Haftalik rapor (coach.report*)
    // =========================================================================

    public function test_the_report_opens_on_the_last_finished_week_with_its_numbers(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $this->oturum($ogrenci, '2026-09-22', 90);
        $this->oturum($ogrenci, '2026-09-24', 120);
        $this->madde($ogrenci, '2026-09-23', ['status' => 'done']);
        $this->madde($ogrenci, '2026-09-25');

        $yanit = $this->actingAs($koc)->get(route('coach.report', $ogrenci))->assertOk();

        $yanit->assertSee('21 - 27 Eylül 2026')
            ->assertSee('3 sa 30 dk')
            ->assertSee('1 / 2')
            ->assertSee('Koç yorumu')
            ->assertSee(route('coach.report.comment', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), false)
            ->assertSee(route('coach.report.regenerate', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), false)
            // Hafta oklari.
            ->assertSee('?hafta=2026-09-14', false)
            ->assertSee('?hafta=2026-09-28', false);

        $rapor = WeeklyReport::sole();
        $this->assertSame(self::GECEN_HAFTA, $rapor->week_start->toDateString());
        $this->assertSame(210, $rapor->payload['minutes']);
        $this->assertSame(2, $rapor->payload['attended_days']);
    }

    public function test_a_report_for_an_empty_week_renders_without_errors(): void
    {
        $ogrenci = User::factory()->student()->create(['name' => 'Şule Ağaoğlu']);
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.report', [$ogrenci, 'hafta' => '2026-09-01']))
            ->assertOk()
            ->assertSee('31 Ağustos - 6 Eylül 2026')
            ->assertSee('0 dk');
    }

    public function test_the_current_week_explains_itself_and_hides_the_comment_form(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.report', [$ogrenci, 'hafta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Hafta tamamlanınca hazır olacak')
            ->assertDontSee('name="coach_comment"', false)
            ->assertDontSee('Sayıları yeniden hesapla');

        $this->assertDatabaseCount('weekly_reports', 0);
    }

    public function test_a_junk_report_week_falls_back_to_the_last_finished_week(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.report', [$ogrenci, 'hafta' => 'geçen-hafta']))
            ->assertOk()
            ->assertSee('21 - 27 Eylül 2026');
    }

    public function test_a_coach_writes_and_then_clears_the_comment(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
        $sayfa = route('coach.report', [$ogrenci, 'hafta' => self::GECEN_HAFTA]);
        $yorum = 'Tempo iyi; deneme sayısını artıralım. Çarşamba günü geometriye ağırlık.';

        $this->actingAs($koc)->get($sayfa)->assertOk();

        $this->actingAs($koc)->from($sayfa)
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), ['coach_comment' => $yorum])
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Yorum kaydedildi.');

        $this->assertSame($yorum, WeeklyReport::sole()->coach_comment);

        $this->actingAs($koc)->get($sayfa)->assertSee($yorum);
        $this->actingAs($veli)->get(route('parent.report', [$ogrenci, 'hafta' => self::GECEN_HAFTA]))
            ->assertOk()
            ->assertSee($yorum);

        // Bosluk yorumu siler.
        $this->actingAs($koc)->from($sayfa)
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), ['coach_comment' => '   '])
            ->assertRedirect($sayfa);
        $this->assertNull(WeeklyReport::sole()->coach_comment);
    }

    public function test_a_comment_can_be_written_before_the_page_was_ever_opened(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), ['coach_comment' => 'İlk yorum'])
            ->assertRedirect();

        $this->assertSame('İlk yorum', WeeklyReport::sole()->coach_comment);
    }

    public function test_comment_validation_and_unfinished_week(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->from(route('coach.report', $ogrenci))
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::GECEN_HAFTA]), ['coach_comment' => str_repeat('ş', 2001)])
            ->assertSessionHasErrors('coach_comment');

        $this->actingAs($koc)
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::HAFTA]), ['coach_comment' => 'Erken yorum'])
            ->assertNotFound();

        $this->assertNull(WeeklyReport::first()?->coach_comment);
    }

    public function test_regenerating_refreshes_the_numbers_but_keeps_the_comment(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $sayfa = route('coach.report', [$ogrenci, 'hafta' => self::GECEN_HAFTA]);
        $this->oturum($ogrenci, '2026-09-22', 60);

        $this->actingAs($koc)->get($sayfa)->assertOk()->assertSee('1 sa');
        WeeklyReport::sole()->update(['coach_comment' => 'Güzel hafta.']);

        // Gecikmis onay: ayni haftaya yeni bir oturum.
        $this->oturum($ogrenci, '2026-09-26', 45);
        $this->actingAs($koc)->get($sayfa)->assertSee('1 sa')->assertDontSee('1 sa 45 dk');

        $this->actingAs($koc)->from($sayfa)
            ->post(route('coach.report.regenerate', [$ogrenci, 'hafta' => self::GECEN_HAFTA]))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Rapor yeniden hesaplandı.');

        $rapor = WeeklyReport::sole();
        $this->assertSame(105, $rapor->payload['minutes']);
        $this->assertSame('Güzel hafta.', $rapor->coach_comment);

        $this->actingAs($koc)->get($sayfa)->assertSee('1 sa 45 dk')->assertSee('Güzel hafta.');

        // Suren hafta yeniden hesaplanamaz.
        $this->actingAs($koc)->post(route('coach.report.regenerate', [$ogrenci, 'hafta' => self::HAFTA]))->assertNotFound();
    }

    public function test_a_coach_cannot_reach_an_unassigned_students_report(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci('Gökhan Işık');
        $koc = $this->koc($ogrenci);

        $this->actingAs($koc)->get(route('coach.report', $baskasi))->assertForbidden();
        $this->actingAs($koc)->post(route('coach.report.comment', [$baskasi, 'hafta' => self::GECEN_HAFTA]), ['coach_comment' => 'Sızma'])->assertForbidden();
        $this->actingAs($koc)->post(route('coach.report.regenerate', [$baskasi, 'hafta' => self::GECEN_HAFTA]))->assertForbidden();

        $this->assertDatabaseCount('weekly_reports', 0);
    }

    public function test_removing_the_assignment_closes_every_coach_door(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->koc($ogrenci);
        $madde = $this->madde($ogrenci, '2026-09-30');

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertOk();

        $koc->coachStudents()->detach($ogrenci->id);

        $this->actingAs($koc)->get($this->planSayfasi($ogrenci))->assertForbidden();
        $this->actingAs($koc)->delete(route('coach.plan.destroy', $madde))->assertForbidden();
        $this->actingAs($koc)->get(route('coach.plan.index'))->assertOk()->assertDontSee('Ayşe Yılmaz');
    }
}
