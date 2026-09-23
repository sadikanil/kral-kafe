<?php

namespace Tests\Feature;

use App\Enums\CommitmentKind;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\Package;
use App\Models\PrivateLessonSlot;
use App\Models\StudentCommitment;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use App\Support\WeekPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 30c - Takvimli plan.
 *
 * Koc haftalik takvimde gune ders + konu koyar. Ayni takvimde ozel ders,
 * denemeler ve okul/dershane gibi sabit program gorunur. Ogrenci serbest
 * denemeyi istedigi gune koyar. Hafta: 28 Eylul (Pzt) - 4 Ekim 2026.
 */
class CalendarPlanTest extends TestCase
{
    use RefreshDatabase;

    private const HAFTA = '2026-09-28';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 12:00', config('kafe.timezone')));
    }

    private function ogrenci(?Package $paket = null): User
    {
        return User::factory()->student()
            ->withPackage($paket ?? Package::factory()->tier3()->create())
            ->create(['grade' => '12', 'field' => 'say']);
    }

    private function koc(User $ogrenci): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

    private function ders(string $kod = 'ayt_fizik'): Subject
    {
        return Subject::where('code', $kod)->sole();
    }

    private function konu(Subject $ders, string $ad = 'Tork ve Denge'): SubjectTopic
    {
        return SubjectTopic::create(['subject_id' => $ders->id, 'name' => $ad]);
    }

    private function madde(User $ogrenci, string $gun, array $ek = []): StudyPlanItem
    {
        return StudyPlanItem::create(array_merge([
            'student_id' => $ogrenci->id, 'title' => 'Paragraf', 'plan_date' => $gun,
            'week_start' => self::HAFTA, 'period' => 'week',
        ], $ek));
    }

    // --- Haftalik gorunum (WeekPlan) --------------------------------------------

    public function test_the_week_has_seven_days_from_monday(): void
    {
        $gunler = WeekPlan::for($this->ogrenci(), '2026-10-01');

        $this->assertCount(7, $gunler);
        $this->assertSame('2026-09-28', $gunler[0]['date']);
        $this->assertSame('2026-10-04', $gunler[6]['date']);
        $this->assertTrue($gunler[1]['isToday']);
    }

    public function test_items_land_on_their_day_ordered_by_time(): void
    {
        $ogrenci = $this->ogrenci();
        $aksam = $this->madde($ogrenci, '2026-09-30', ['title' => 'Akşam', 'starts_at' => '19:00']);
        $sabah = $this->madde($ogrenci, '2026-09-30', ['title' => 'Sabah', 'starts_at' => '09:00']);
        $saatsiz = $this->madde($ogrenci, '2026-09-30', ['title' => 'Saatsiz']);
        $this->madde($ogrenci, '2026-10-06', ['title' => 'Gelecek hafta']);

        $gun = WeekPlan::for($ogrenci, self::HAFTA)[2];

        $this->assertSame([$sabah->id, $aksam->id, $saatsiz->id], $gun['items']->pluck('id')->all());
    }

    public function test_commitments_repeat_on_their_weekday(): void
    {
        $ogrenci = $this->ogrenci();
        StudentCommitment::create(['student_id' => $ogrenci->id, 'kind' => 'okul', 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '15:00']);

        $gunler = WeekPlan::for($ogrenci, self::HAFTA);

        $this->assertCount(1, $gunler[0]['commitments']);
        $this->assertCount(0, $gunler[1]['commitments']);
    }

    public function test_dated_exams_show_but_flexible_ones_do_not(): void
    {
        $ogrenci = $this->ogrenci();
        $sabit = ExamEvent::create(['title' => 'Mikro Orijinal', 'exam_type' => 'tyt', 'exam_date' => '2026-10-02']);
        ExamEvent::create(['title' => 'Hız ve Renk', 'exam_type' => 'tyt', 'exam_date' => '2026-10-01',
            'is_flexible' => true, 'available_until' => '2026-10-31']);

        $gunler = WeekPlan::for($ogrenci, self::HAFTA);

        $this->assertSame([$sabit->id], $gunler[4]['exams']->pluck('id')->all());
        $this->assertCount(0, $gunler[3]['exams']);
    }

    public function test_private_lessons_show_for_tier_three(): void
    {
        $ogrenci = $this->ogrenci();
        PrivateLessonSlot::create(['student_id' => $ogrenci->id, 'weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:00', 'starts_on' => '2026-09-01']);

        $this->assertCount(1, WeekPlan::for($ogrenci, self::HAFTA)[2]['lessons']);
    }

    // --- Koc: ekle, tasi, sil ------------------------------------------------

    public function test_the_coach_adds_a_topic_to_a_day(): void
    {
        $ogrenci = $this->ogrenci();
        $fizik = $this->ders();
        $konu = $this->konu($fizik);

        $this->actingAs($this->koc($ogrenci))->post(route('coach.plan.store', $ogrenci), [
            'plan_date' => '2026-10-01', 'subject_id' => $fizik->id, 'subject_topic_id' => $konu->id,
            'starts_at' => '16:00', 'duration_minutes' => 90,
        ])->assertRedirect();

        $madde = StudyPlanItem::sole();
        $this->assertSame('2026-10-01', $madde->plan_date->toDateString());
        $this->assertSame(self::HAFTA, $madde->week_start->toDateString(), 'Haftalik ilerleme icin');
        $this->assertSame('Tork ve Denge', $madde->title);
        $this->assertSame(90, (int) $madde->duration_minutes);
    }

    public function test_a_note_becomes_the_title(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->koc($ogrenci))->post(route('coach.plan.store', $ogrenci), [
            'plan_date' => '2026-10-01', 'subject_id' => $this->ders()->id, 'title' => '40 soru',
        ]);

        $this->assertSame('40 soru', StudyPlanItem::sole()->title);
    }

    public function test_a_topic_of_another_subject_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $baskaKonu = $this->konu($this->ders('ayt_kimya'), 'Mol Kavramı');

        $this->actingAs($this->koc($ogrenci))->from(route('coach.plan.show', $ogrenci))
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-10-01', 'subject_id' => $this->ders()->id, 'subject_topic_id' => $baskaKonu->id,
            ])
            ->assertSessionHasErrors('subject_topic_id');
    }

    public function test_an_item_needs_a_subject_or_a_note(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->koc($ogrenci))->from(route('coach.plan.show', $ogrenci))
            ->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-10-01'])
            ->assertSessionHasErrors('subject_id');
    }

    public function test_the_coach_moves_an_item_to_another_week(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci, '2026-10-01');

        $this->actingAs($this->koc($ogrenci))
            ->patch(route('coach.plan.move', $madde), ['plan_date' => '2026-10-06'])
            ->assertRedirect();

        $this->assertSame('2026-10-06', $madde->fresh()->plan_date->toDateString());
        $this->assertSame('2026-10-05', $madde->fresh()->week_start->toDateString());
    }

    public function test_another_coach_cannot_touch_the_plan(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci, '2026-10-01');
        $baskaKoc = User::factory()->create(['role' => Role::Coach->value]);

        $this->actingAs($baskaKoc)->patch(route('coach.plan.move', $madde), ['plan_date' => '2026-10-06'])->assertForbidden();
        $this->actingAs($baskaKoc)->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-10-01', 'title' => 'x'])->assertForbidden();
    }

    public function test_the_coach_page_shows_the_week_calendar(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Vektörler']);
        StudentCommitment::create(['student_id' => $ogrenci->id, 'kind' => 'dershane', 'title' => 'Limit', 'weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '13:00']);
        ExamEvent::create(['title' => 'Mikro Orijinal', 'exam_type' => 'tyt', 'exam_date' => '2026-10-02']);

        $this->actingAs($this->koc($ogrenci))->get(route('coach.plan.show', [$ogrenci, 'hafta' => self::HAFTA]))
            ->assertOk()
            ->assertSee('Vektörler')
            ->assertSee('Dershane · Limit')
            ->assertSee('Mikro Orijinal');
    }

    // --- Sabit program -------------------------------------------------------

    public function test_the_coach_adds_school_on_weekdays(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->koc($ogrenci))->post(route('coach.commitments.store', $ogrenci), [
            'kind' => 'okul', 'weekdays' => [1, 2, 3, 4, 5], 'commitment_starts_at' => '08:00', 'commitment_ends_at' => '15:00',
        ])->assertRedirect();

        $this->assertSame([1, 2, 3, 4, 5], StudentCommitment::orderBy('weekday')->pluck('weekday')->all());
        $this->assertSame(CommitmentKind::School, StudentCommitment::first()->kind);
    }

    public function test_a_commitment_must_end_after_it_starts(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->koc($ogrenci))->from(route('coach.plan.show', $ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'okul', 'weekdays' => [1], 'commitment_starts_at' => '15:00', 'commitment_ends_at' => '08:00',
            ])
            ->assertSessionHasErrors('commitment_ends_at');
    }

    public function test_the_coach_removes_a_commitment(): void
    {
        $ogrenci = $this->ogrenci();
        $okul = StudentCommitment::create(['student_id' => $ogrenci->id, 'kind' => 'okul', 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '15:00']);

        $this->actingAs($this->koc($ogrenci))->delete(route('coach.commitments.destroy', $okul))->assertRedirect();

        $this->assertModelMissing($okul);
    }

    // --- Ogrenci: kendi takvimi ve serbest deneme -------------------------------

    private function serbest(): ExamEvent
    {
        return ExamEvent::create(['title' => 'Hız ve Renk', 'exam_type' => 'tyt', 'exam_date' => '2026-10-01',
            'is_flexible' => true, 'available_until' => '2026-10-31', 'note' => 'Deneme Kulübü']);
    }

    public function test_the_student_sees_their_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Vektörler']);
        $this->serbest();

        $this->actingAs($ogrenci)->get(route('user.plan'))
            ->assertOk()
            ->assertSee('Vektörler')
            ->assertSee('Hız ve Renk')
            ->assertSee(route('user.plan.exam'), false);
    }

    public function test_the_student_puts_a_flexible_exam_on_a_day(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbest();

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), [
            'exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10',
        ])->assertRedirect();

        $madde = StudyPlanItem::sole();
        $this->assertSame($deneme->id, $madde->exam_event_id);
        $this->assertSame('2026-10-10', $madde->plan_date->toDateString());
        $this->assertSame('Hız ve Renk (TYT)', $madde->title);
        $this->assertSame($ogrenci->id, $madde->created_by);
    }

    public function test_the_day_must_be_inside_the_window(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->from(route('user.plan'))->post(route('user.plan.exam'), [
            'exam_event_id' => $this->serbest()->id, 'plan_date' => '2026-11-02',
        ])->assertSessionHasErrors('plan_date');
    }

    public function test_a_dated_exam_cannot_be_moved_by_the_student(): void
    {
        $ogrenci = $this->ogrenci();
        $sabit = ExamEvent::create(['title' => 'Apotemi', 'exam_type' => 'tyt', 'exam_date' => '2026-11-06']);

        $this->actingAs($ogrenci)->from(route('user.plan'))->post(route('user.plan.exam'), [
            'exam_event_id' => $sabit->id, 'plan_date' => '2026-10-10',
        ])->assertSessionHasErrors('exam_event_id');
    }

    /** Serbest deneme = Deneme Kulubu; hakki olmayan ogrenci koyamaz. */
    public function test_a_student_without_the_exam_club_cannot_schedule(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1()->create());

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), [
            'exam_event_id' => $this->serbest()->id, 'plan_date' => '2026-10-10',
        ])->assertForbidden();
    }

    public function test_the_student_removes_their_own_exam_item_only(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->madde($ogrenci, '2026-10-10', ['exam_event_id' => $this->serbest()->id, 'created_by' => $ogrenci->id]);
        $kocunki = $this->madde($ogrenci, '2026-10-01');

        $this->actingAs($ogrenci)->delete(route('user.plan.exam.destroy', $deneme))->assertRedirect();
        $this->actingAs($ogrenci)->delete(route('user.plan.exam.destroy', $kocunki))->assertForbidden();

        $this->assertModelMissing($deneme);
        $this->assertModelExists($kocunki);
    }

    public function test_the_parent_sees_the_week_read_only(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-30', ['title' => 'Vektörler']);
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($ogrenci);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Vektörler')
            ->assertDontSee(route('coach.plan.store', $ogrenci), false);
    }
}
