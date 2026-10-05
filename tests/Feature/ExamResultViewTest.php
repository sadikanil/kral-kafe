<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeakTopic;
use App\Support\ExamResultDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Deneme sonucu detayi ve deneme analizi (1 Ekim 2026).
 *
 *  - Detay sayfasi: ogrenci kendisi, veli kendi cocugu, koc atandigi
 *    ogrenci, yonetici herkes. Onceki ayni tur denemeye gore degisim.
 *  - Deneme analizi: koc yalnizca atandigi ogrencileri yan yana gorur,
 *    yonetici hepsini; en cok eksik cikan konular.
 */
class ExamResultViewTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;
    private User $koc;
    private User $ayse;
    private User $burak;
    private Subject $turkce;
    private ExamEvent $eski;
    private ExamEvent $yeni;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 10:00', config('kafe.timezone')));

        $this->yonetici = User::factory()->admin()->create();
        $this->koc = User::factory()->parent()->create(['name' => 'Koç Veli', 'is_coach' => true]);
        $this->ayse = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Ayşe Tan']);
        $this->burak = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Burak Er']);
        $this->koc->coachStudents()->attach($this->ayse->id);

        $this->turkce = Subject::where('exam_type', 'tyt')->orderBy('sort_order')->firstOrFail();
        $this->eski = ExamEvent::create(['title' => 'TYT Deneme 1', 'exam_type' => 'tyt', 'exam_date' => '2026-09-13']);
        $this->yeni = ExamEvent::create(['title' => 'TYT Deneme 2', 'exam_type' => 'tyt', 'exam_date' => '2026-09-27']);

        $this->sonuc($this->ayse, $this->eski, 20, 8);
        $this->sonuc($this->ayse, $this->yeni, 30, 4, [
            ['subject' => 'Türkçe', 'topic' => 'Paragraf', 'questions' => 10, 'correct' => 9, 'wrong' => 1, 'blank' => 0],
            ['subject' => 'Türkçe', 'topic' => 'Yazım Kuralları', 'questions' => 4, 'correct' => 1, 'wrong' => 3, 'blank' => 0],
        ], rank: 7, total: 120);
        $this->sonuc($this->burak, $this->yeni, 25, 0, [
            ['subject' => 'Türkçe', 'topic' => 'Yazım Kuralları', 'questions' => 4, 'correct' => 0, 'wrong' => 4, 'blank' => 0],
        ]);
    }

    private function sonuc(User $ogrenci, ExamEvent $deneme, int $dogru, int $yanlis, array $konular = [], ?int $rank = null, ?int $total = null): ExamResult
    {
        $sonuc = ExamResult::create([
            'exam_event_id' => $deneme->id, 'student_id' => $ogrenci->id, 'topics' => $konular,
            'rank_institution' => $rank, 'total_institution' => $total, 'entered_by' => $this->yonetici->id,
        ]);
        $sonuc->subjects()->create(['subject_id' => $this->turkce->id, 'correct' => $dogru, 'wrong' => $yanlis, 'blank' => 0]);

        return $sonuc;
    }

    private function ayseninYenisi(): ExamResult
    {
        return ExamResult::where('student_id', $this->ayse->id)->where('exam_event_id', $this->yeni->id)->sole();
    }

    public function test_the_detail_compares_with_the_previous_exam_of_the_same_type(): void
    {
        $detay = ExamResultDetail::for($this->ayseninYenisi());

        // 30-4/4 = 29 ; 20-8/4 = 18 -> +11
        $this->assertSame(11.0, $detay['netChange']);
        $this->assertSame(11.0, $detay['subjects'][0]['change']);
        $this->assertSame('TYT Deneme 1', $detay['previous']->event->title);
        $this->assertSame(['Yazım Kuralları'], array_column($detay['weak'], 'topic'));
        $this->assertSame(['Yazım Kuralları', 'Paragraf'], array_column($detay['topicGroups']['Türkçe'], 'topic'));
    }

    /** Bolum ara toplamlari: iki ve daha fazla dersi olan bolume (5 Ekim 2026). */
    public function test_the_subject_table_adds_section_subtotals(): void
    {
        $ders = fn (string $kod) => Subject::where('code', $kod)->value('id');
        $deneme = ExamEvent::create(['title' => 'TYT Deneme 3', 'exam_type' => 'tyt', 'exam_date' => '2026-09-28']);
        $sonuc = ExamResult::create(['exam_event_id' => $deneme->id, 'student_id' => $this->burak->id, 'entered_by' => $this->yonetici->id]);
        foreach ([['tyt_turkce', 30, 4], ['tyt_matematik', 20, 4], ['geometri', 4, 0], ['tyt_fizik', 4, 0], ['tyt_kimya', 3, 1]] as [$kod, $d, $y]) {
            $sonuc->subjects()->create(['subject_id' => $ders($kod), 'correct' => $d, 'wrong' => $y, 'blank' => 0]);
        }

        $satirlar = ExamResultDetail::for($sonuc->fresh())['tableRows'];

        $this->assertSame(['TYT Türkçe', 'TYT Matematik', 'Geometri', 'Temel Matematik', 'TYT Fizik', 'TYT Kimya', 'Fen Bilimleri'],
            array_column($satirlar, 'name'));
        $this->assertSame(23.0, $satirlar[3]['net']);   // 19 + 4
        $this->assertSame(24, $satirlar[3]['correct']);
        $this->assertSame(6.75, $satirlar[6]['net']);   // 4 + 2,75
        $this->assertTrue($satirlar[6]['subtotal']);
        $this->assertNull($satirlar[6]['change']);       // onceki deneme yok

        $this->actingAs($this->burak)->get(route('user.exam-results.show', $sonuc))
            ->assertOk()->assertSee('Temel Matematik')->assertSee('table-subtotal', false);
    }

    public function test_the_student_sees_their_detail_page(): void
    {
        $this->actingAs($this->ayse)->get(route('user.exam-results.show', $this->ayseninYenisi()))
            ->assertOk()
            ->assertSee('TYT Deneme 2')
            ->assertSee('+11,00')
            ->assertSee('120 kişide 7.')
            ->assertSee('Yazım Kuralları')
            ->assertSee('progress-bar-weak', false)
            ->assertDontSee('Plan sayfası →')
            ->assertDontSee('Ödev ver');
    }

    public function test_the_coach_sees_only_assigned_students_in_the_analysis(): void
    {
        $this->actingAs($this->koc)->get(route('coach.exams.index'))
            ->assertOk()->assertSee('TYT Deneme 2')->assertSee('1 öğrenci');

        $this->actingAs($this->koc)->get(route('coach.exams.show', $this->yeni))
            ->assertOk()
            ->assertSee('Ayşe Tan')
            ->assertDontSee('Burak Er')
            ->assertSee('En çok eksik çıkan konular')
            ->assertSee('Türkçe · Yazım Kuralları');

        $this->actingAs($this->koc)->get(route('coach.exams.result', $this->ayseninYenisi()))
            ->assertOk()->assertSee('Ayşe Tan')->assertSee('Plan sayfası →')
            ->assertSee(route('coach.plan.show', $this->ayse), false);

        $burakinki = ExamResult::where('student_id', $this->burak->id)->sole();
        $this->actingAs($this->koc)->get(route('coach.exams.result', $burakinki))->assertForbidden();
    }

    /** Eksik konu satirinda tek dokunusla odev; eklenince "Planda" (5 Ekim 2026). */
    public function test_the_coach_assigns_homework_from_a_weak_topic_row(): void
    {
        $konu = WeakTopic::create(['student_id' => $this->ayse->id, 'subject_id' => $this->turkce->id,
            'topic' => 'Yazım Kuralları', 'source' => 'exam', 'created_by' => $this->yonetici->id]);
        $sayfa = route('coach.exams.result', $this->ayseninYenisi());

        $this->actingAs($this->koc)->get($sayfa)
            ->assertSee(route('coach.topics.plan', $konu), false)
            ->assertSee('Ödev ver')
            ->assertDontSee('Planda');

        $this->actingAs($this->koc)->from($sayfa)->post(route('coach.topics.plan', $konu))->assertRedirect($sayfa);

        $madde = StudyPlanItem::where('student_id', $this->ayse->id)->sole();
        $this->assertSame('Yazım Kuralları', $madde->title);
        $this->assertSame(StudyPlanItem::TAG_HOMEWORK, $madde->tag);

        $this->actingAs($this->koc)->get($sayfa)
            ->assertSee('Planda')
            ->assertDontSee(route('coach.topics.plan', $konu), false);
    }

    /** Kocun plan sayfasinda son deneme ozeti ve detaya baglanti (5 Ekim 2026). */
    public function test_the_coach_plan_page_shows_the_latest_exam(): void
    {
        $this->actingAs($this->koc)->get(route('coach.plan.show', $this->ayse))
            ->assertOk()
            ->assertSee('Son deneme: TYT Deneme 2')
            ->assertSee('29,00')
            ->assertSee('(+11,00)')
            ->assertSee('1 eksik konu')
            ->assertSee(route('coach.exams.result', $this->ayseninYenisi()), false);
    }

    public function test_the_plan_page_without_exams_has_no_summary(): void
    {
        $this->koc->coachStudents()->attach($this->burak->id);
        ExamResult::where('student_id', $this->burak->id)->delete();

        $this->actingAs($this->koc)->get(route('coach.plan.show', $this->burak))
            ->assertOk()->assertDontSee('Son deneme:');
    }

    public function test_the_admin_sees_every_student_side_by_side(): void
    {
        $this->actingAs($this->yonetici)->get(route('coach.exams.show', $this->yeni))
            ->assertOk()
            ->assertSeeInOrder(['Ayşe Tan', 'Burak Er'])
            ->assertSee('2 öğrencide');
    }

    public function test_students_and_plain_parents_cannot_open_the_analysis(): void
    {
        $this->actingAs($this->ayse)->get(route('coach.exams.index'))->assertForbidden();
        $this->actingAs(User::factory()->parent()->create())->get(route('coach.exams.show', $this->yeni))->assertForbidden();
    }

    public function test_the_menu_shows_exam_analysis_to_coaches_and_admin(): void
    {
        $this->actingAs($this->koc)->get(route('coach.plan.index'))->assertSee('Deneme Analizi');
        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))->assertSee('Deneme Analizi');
    }
}
