<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Support\ExamResultDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Odev ise yaradi mi? (6 Ekim 2026)
 *
 * Deneme detayinda onceki ayni tur denemenin eksik konulari bu denemede:
 * basari once -> simdi, arada verilen odevin durumu ve kurala gore durum.
 */
class TopicFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;
    private User $koc;
    private User $ogrenci;
    private ExamResult $yeni;

    protected function setUp(): void
    {
        parent::setUp();
        $tz = config('kafe.timezone');
        $this->travelTo(Carbon::parse('2026-09-01 10:00', $tz));
        $this->yonetici = User::factory()->admin()->create();
        $this->koc = User::factory()->create(['role' => 'coach']);
        $this->ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Ayşe Tan']);
        $this->koc->coachStudents()->attach($this->ogrenci->id);
        $mat = Subject::where('code', 'tyt_matematik')->firstOrFail();

        $konu = fn (string $ad, int $soru, int $dogru) => ['subject' => $mat->name, 'topic' => $ad, 'questions' => $soru, 'correct' => $dogru, 'wrong' => $soru - $dogru, 'blank' => 0];

        $this->travelTo(Carbon::parse('2026-09-15 10:00', $tz));
        $this->sonuc('TYT Deneme 1', '2026-09-13', $mat, [
            $konu('Türev', 3, 1), $konu('Limit', 4, 0), $konu('Olasılık', 2, 0), $konu('Mantık', 2, 0), $konu('Sayılar', 5, 5),
        ]);

        // Arada koc odev verdi: Turev bitti, Limit bitmedi.
        $this->travelTo(Carbon::parse('2026-09-18 10:00', $tz));
        $odev = fn (string $ad, string $durum) => StudyPlanItem::create(['student_id' => $this->ogrenci->id, 'subject_id' => $mat->id,
            'title' => $ad, 'plan_date' => '2026-09-18', 'week_start' => '2026-09-14', 'created_by' => $this->koc->id,
            'tag' => StudyPlanItem::TAG_HOMEWORK, 'status' => $durum]);
        $odev('Türev', 'done');
        $odev('Limit', 'open');

        $this->travelTo(Carbon::parse('2026-09-29 10:00', $tz));
        $this->yeni = $this->sonuc('TYT Deneme 2', '2026-09-27', $mat, [
            $konu('Türev', 6, 4), $konu('Limit', 4, 1), $konu('Olasılık', 1, 1),
        ]);

        // Sonraki denemeden SONRA verilen odev bu karsilastirmaya girmez.
        $this->travelTo(Carbon::parse('2026-09-30 10:00', $tz));
        $odev('Mantık', 'open');
    }

    private function sonuc(string $ad, string $gun, Subject $ders, array $konular): ExamResult
    {
        $deneme = ExamEvent::create(['title' => $ad, 'exam_type' => 'tyt', 'exam_date' => $gun]);
        $sonuc = ExamResult::create(['exam_event_id' => $deneme->id, 'student_id' => $this->ogrenci->id,
            'topics' => $konular, 'entered_by' => $this->yonetici->id]);
        $sonuc->subjects()->create(['subject_id' => $ders->id, 'correct' => 20, 'wrong' => 4, 'blank' => 0]);

        return $sonuc;
    }

    public function test_last_exams_weak_topics_are_measured_again_with_their_homework(): void
    {
        $satirlar = ExamResultDetail::for($this->yeni)['followUp'];

        $this->assertSame(
            [
                ['Türev', 33, 67, 'fixed', 'done'],
                ['Limit', 0, 25, 'weak', 'open'],
                ['Olasılık', 0, 100, 'few', null],
                ['Mantık', 0, null, 'absent', null],
            ],
            array_map(fn ($s) => [$s['topic'], $s['before'], $s['after'], $s['status'], $s['homework']], $satirlar),
        );
    }

    public function test_the_detail_page_shows_it_to_the_student_and_the_coach(): void
    {
        foreach ([$this->koc, $this->ogrenci] as $kisi) {
            $adres = $kisi->is($this->koc) ? route('coach.exams.result', $this->yeni) : route('user.exam-results.show', $this->yeni);

            $this->actingAs($kisi)->get($adres)->assertOk()
                ->assertSeeInOrder(['Önceki denemenin eksikleri', 'Türev', "%33\u{00A0}→\u{00A0}%67", "Ödev\u{00A0}tamamlandı", 'Artık eksik değil',
                    'Limit', "%0\u{00A0}→\u{00A0}%25", "Ödev\u{00A0}bitmedi", 'Hâlâ eksik', 'Olasılık', '1 soru', 'Mantık', "%0\u{00A0}→\u{00A0}—", 'Bu denemede yok']);
        }
    }

    public function test_the_first_exam_has_nothing_to_follow(): void
    {
        $ilk = ExamResult::whereHas('event', fn ($q) => $q->where('title', 'TYT Deneme 1'))->sole();

        $this->assertSame([], ExamResultDetail::for($ilk)['followUp']);
        $this->actingAs($this->koc)->get(route('coach.exams.result', $ilk))->assertOk()->assertDontSee('Önceki denemenin eksikleri');
    }
}
