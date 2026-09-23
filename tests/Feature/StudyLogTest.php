<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StudyUnit;
use App\Models\StudyLog;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\User;
use App\Services\WeeklyReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 28 - Calisma kaydi: "Tarih · 200 soru tamamlandi".
 *
 * Ogrenci sayactan girer; veli ve koc gorur. Eski "Ne calisiyorsun?" ders
 * secimi kalkti: oturumun dersi son kayittan gelir, ders kirilimi bozulmaz.
 */
class StudyLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sabit gunduz saati: testler "bir saat once acilmis" oturum kuruyor;
     * gercek saat kapanistan (21:00) sonraysa oturum kendiliginden kapanir.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-16 14:00', config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->create();
    }

    private function ders(string $ad = 'Tarih'): Subject
    {
        return Subject::create(['name' => $ad, 'exam_type' => 'tyt']);
    }

    private function acikOturum(User $ogrenci): StudySession
    {
        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => now()->subHour(),
        ]);
    }

    private function kayit(User $ogrenci, StudySession $oturum, int $adet, StudyUnit $birim = StudyUnit::Question, ?Subject $ders = null): StudyLog
    {
        return StudyLog::create([
            'student_id' => $ogrenci->id,
            'study_session_id' => $oturum->id,
            'subject_id' => $ders?->id,
            'amount' => $adet,
            'unit' => $birim->value,
        ]);
    }

    // --- Giris ---------------------------------------------------------------

    public function test_a_student_logs_work_during_an_open_session(): void
    {
        $ogrenci = $this->ogrenci();
        $ders = $this->ders();
        $oturum = $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)
            ->post(route('session.logs.store'), [
                'subject_id' => $ders->id, 'amount' => 200, 'unit' => 'soru', 'note' => 'Osmanlı',
            ])
            ->assertRedirect(route('session.timer'));

        $kayit = StudyLog::sole();
        $this->assertSame($oturum->id, $kayit->study_session_id);
        $this->assertSame(200, $kayit->amount);
        $this->assertSame(StudyUnit::Question, $kayit->unit);
        $this->assertSame('Osmanlı', $kayit->note);
    }

    /** Oturumun dersi son kayittan gelir: ders kirilimi eskisi gibi calisir. */
    public function test_logging_sets_the_session_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $tarih = $this->ders('Tarih');

        $this->actingAs($ogrenci)->post(route('session.logs.store'), [
            'subject_id' => $tarih->id, 'amount' => 20, 'unit' => 'sayfa',
        ]);

        $this->assertSame($tarih->id, $oturum->fresh()->subject_id);
    }

    /** "Genel" kaydi (ders yok) oturumun onceki dersini SILMEZ. */
    public function test_a_general_log_keeps_the_session_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $tarih = $this->ders('Tarih');
        $oturum = $this->acikOturum($ogrenci);
        $oturum->update(['subject_id' => $tarih->id]);

        $this->actingAs($ogrenci)->post(route('session.logs.store'), ['amount' => 1, 'unit' => 'deneme']);

        $this->assertSame($tarih->id, $oturum->fresh()->subject_id);
        $this->assertNull(StudyLog::sole()->subject_id);
    }

    public function test_logging_needs_an_open_session(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)
            ->post(route('session.logs.store'), ['amount' => 200, 'unit' => 'soru'])
            ->assertRedirect(route('user.dashboard'));

        $this->assertSame(0, StudyLog::count());
    }

    public function test_invalid_input_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->from(route('session.timer'))
            ->post(route('session.logs.store'), ['amount' => 0, 'unit' => 'kilo', 'subject_id' => 9999])
            ->assertSessionHasErrors(['amount', 'unit', 'subject_id']);

        $this->assertSame(0, StudyLog::count());
    }

    // --- Silme ---------------------------------------------------------------

    public function test_a_student_deletes_a_mistaken_log(): void
    {
        $ogrenci = $this->ogrenci();
        $kayit = $this->kayit($ogrenci, $this->acikOturum($ogrenci), 200);

        $this->actingAs($ogrenci)->delete(route('session.logs.destroy', $kayit))->assertRedirect();

        $this->assertModelMissing($kayit);
    }

    public function test_a_student_cannot_delete_someone_elses_log(): void
    {
        $sahibi = $this->ogrenci();
        $kayit = $this->kayit($sahibi, $this->acikOturum($sahibi), 200);

        $this->actingAs($this->ogrenci())->delete(route('session.logs.destroy', $kayit))->assertForbidden();

        $this->assertModelExists($kayit);
    }

    /** Bitmis oturumun kaydi kalir: veli gordukten sonra kaybolmasin. */
    public function test_a_log_of_a_finished_session_cannot_be_deleted(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $kayit = $this->kayit($ogrenci, $oturum, 200);
        $oturum->update(['ended_at' => now(), 'duration_minutes' => 60]);

        $this->actingAs($ogrenci)->delete(route('session.logs.destroy', $kayit))->assertForbidden();

        $this->assertModelExists($kayit);
    }

    // --- Toplamlar -----------------------------------------------------------

    public function test_totals_are_summed_per_unit_in_unit_order(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $this->kayit($ogrenci, $oturum, 3, StudyUnit::Topic);
        $this->kayit($ogrenci, $oturum, 200, StudyUnit::Question);
        $this->kayit($ogrenci, $oturum, 150, StudyUnit::Question);

        $this->assertSame(['soru' => 350, 'konu' => 3], StudyLog::totals(StudyLog::all()));
    }

    public function test_totals_of_nothing_are_empty(): void
    {
        $this->assertSame([], StudyLog::totals(collect()));
    }

    // --- Ekranda -------------------------------------------------------------

    public function test_the_timer_shows_todays_logs_and_totals(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $this->kayit($ogrenci, $oturum, 200, StudyUnit::Question, $this->ders('Tarih'));
        $this->kayit($ogrenci, $oturum, 150, StudyUnit::Question);

        $this->actingAs($ogrenci)->get(route('session.timer'))
            ->assertOk()
            ->assertSee('Tarih · 200 soru')
            ->assertSee('Genel · 150 soru')
            ->assertSee('350 soru')
            ->assertDontSee('Ne çalışıyorsun?');
    }

    /** Dunun kaydi bugunun listesine karismaz (yerel gun sinirlari). */
    public function test_the_timer_lists_only_todays_logs(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $dun = $this->kayit($ogrenci, $oturum, 777);
        $dun->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($ogrenci)->get(route('session.timer'))->assertOk()->assertDontSee('777 soru');
    }

    public function test_a_parent_sees_their_childs_logs(): void
    {
        $ogrenci = $this->ogrenci();
        $this->kayit($ogrenci, $this->acikOturum($ogrenci), 200, StudyUnit::Question, $this->ders('Tarih'));
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($ogrenci);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Çalışma kayıtları')
            ->assertSee('Tarih · 200 soru');
    }

    public function test_an_assigned_coach_sees_the_logs(): void
    {
        $ogrenci = $this->ogrenci();
        $this->kayit($ogrenci, $this->acikOturum($ogrenci), 40, StudyUnit::Page, $this->ders('Biyoloji'));
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        $this->actingAs($koc)->get(route('coach.plan.show', $ogrenci))
            ->assertOk()
            ->assertSee('Biyoloji · 40 sayfa');
    }

    /** Haftalik rapor da toplamlari tasir (hafta bittikten sonra). */
    public function test_the_weekly_report_carries_the_totals(): void
    {
        $ogrenci = $this->ogrenci();
        $this->travelTo(Carbon::parse('2026-09-16 18:00', config('kafe.timezone')));
        $oturum = $this->acikOturum($ogrenci);
        $this->kayit($ogrenci, $oturum, 200);
        $this->kayit($ogrenci, $oturum, 2, StudyUnit::Exam);

        $this->travelTo(Carbon::parse('2026-09-22 10:00', config('kafe.timezone')));
        $rapor = app(WeeklyReportBuilder::class)->for($ogrenci, '2026-09-14');

        $this->assertSame(['soru' => 200, 'deneme' => 2], $rapor->payload['logged']);
    }
}
