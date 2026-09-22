<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\PlanPeriod;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\StudentParent;
use App\Models\StudyGoal;
use App\Models\StudyPlanItem;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\WeeklyReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 15a - Haftalik veli raporu.
 *
 * Rapor bir ANLIK GORUNTU: uretildigi anda hesaplanip saklaniyor, sonra
 * degismiyor. Sebep, velinin gordugu sayinin altindan kaymamasi - "gecen
 * hafta 14 saat yazmisti" diyen veliyle sistemin ayrisamamasi gerekiyor.
 *
 * Bu yuzden rapor YALNIZCA BITMIS hafta icin uretilir: suren bir haftanin
 * yarisini dondurmak, pazartesi acan veliye haftanin tamami gibi
 * gorunurdu.
 */
class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    /** Raporlanacak hafta: 14-20 Eylul 2026 (pazartesi-pazar). */
    private const HAFTA = '2026-09-14';

    /** Rapor acilis ani: hafta bitmis, yeni hafta basmis. */
    private function haftaSonrasi(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22 10:00', config('kafe.timezone')));
    }

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function veli(User $ogrenci): User
    {
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        return $veli;
    }

    private function atanmisKoc(User $ogrenci): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

    /** Belirtilen gun icin kapanmis bir oturum. */
    private function oturum(User $ogrenci, string $gun, int $dakika, ApprovalStatus $onay = ApprovalStatus::Approved): StudySession
    {
        $bas = Carbon::parse($gun . ' 10:00', config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addMinutes($dakika)->utc(),
            'duration_minutes' => $dakika,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => $onay->value,
        ]);
    }

    private function deneme(User $ogrenci, string $gun, int $dogru, int $yanlis): ExamResult
    {
        $olay = ExamEvent::create([
            'title' => 'Deneme ' . $gun,
            'exam_type' => 'tyt',
            'exam_date' => $gun,
        ]);

        $sonuc = ExamResult::create([
            'exam_event_id' => $olay->id,
            'student_id' => $ogrenci->id,
        ]);

        ExamResultSubject::create([
            'exam_result_id' => $sonuc->id,
            'subject_id' => Subject::create(['name' => 'Ders ' . uniqid(), 'exam_type' => 'tyt'])->id,
            'correct' => $dogru,
            'wrong' => $yanlis,
            'blank' => 0,
        ]);

        return $sonuc->fresh();
    }

    private function uretici(): WeeklyReportBuilder
    {
        return app(WeeklyReportBuilder::class);
    }

    // --- Ne zaman uretilir ---------------------------------------------------

    /**
     * SUREN HAFTA RAPORLANMAZ.
     *
     * Rapor bir anlik goruntu ve saklaniyor; yarim haftayi dondurmak, sali
     * gunu acan veliye haftanin TAMAMI gibi gorunurdu ve o sayi bir daha
     * duzelmezdi.
     */
    public function test_the_current_week_has_no_report_yet(): void
    {
        $ogrenci = $this->ogrenci();
        $this->travelTo(Carbon::parse('2026-09-16 10:00', config('kafe.timezone')));

        $this->assertNull($this->uretici()->for($ogrenci, self::HAFTA));
        $this->assertSame(0, WeeklyReport::count());
    }

    public function test_a_finished_week_produces_a_report(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertNotNull($rapor);
        $this->assertSame(self::HAFTA, $rapor->week_start->toDateString());
        $this->assertNotNull($rapor->generated_at);
    }

    /**
     * Ikinci acilis AYNI satiri dondurur; sayilar velinin altindan kaymaz.
     */
    public function test_opening_twice_does_not_rebuild_the_report(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $ilk = $this->uretici()->for($ogrenci, self::HAFTA);

        // Rapor uretildikten SONRA gelen bir oturum onu degistirmemeli.
        $this->oturum($ogrenci, '2026-09-16', 300);

        $ikinci = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame($ilk->id, $ikinci->id);
        $this->assertSame(120, $ikinci->payload['minutes']);
        $this->assertSame(1, WeeklyReport::count());
    }

    // --- Icerik --------------------------------------------------------------

    public function test_the_report_counts_only_approved_time(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->oturum($ogrenci, '2026-09-16', 180, ApprovalStatus::Pending);
        $this->oturum($ogrenci, '2026-09-17', 90, ApprovalStatus::Rejected);
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame(120, $rapor->payload['minutes']);
        $this->assertSame(1, $rapor->payload['attended_days']);
    }

    public function test_the_report_compares_with_the_previous_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-08', 100);   // onceki hafta
        $this->oturum($ogrenci, '2026-09-15', 160);   // raporlanan hafta
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame(160, $rapor->payload['minutes']);
        $this->assertSame(100, $rapor->payload['previous_minutes']);
        $this->assertSame(60, $rapor->minutesChange());
    }

    /**
     * Hedef O HAFTA YURURLUKTE OLANI.
     *
     * Bugunku hedefi yazsaydik, hedefi sonradan yukseltmek gecmis haftalarin
     * "tuttu mu" cevabini degistirirdi - study_goals'un supersedeOn
     * kararinin tamami bunu onlemek icindi (Dalga 5).
     */
    public function test_the_goal_is_the_one_in_force_that_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 600);

        $eski = StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);

        // Hafta bittikten SONRA hedef yukseltiliyor.
        $eski->supersedeOn('2026-09-21');
        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 1500,
            'effective_from' => '2026-09-21',
        ]);

        $this->haftaSonrasi();
        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame(600, $rapor->payload['goal_minutes']);
        $this->assertTrue($rapor->goalMet(), 'O hafta hedef tutturulmustu');
    }

    public function test_a_student_without_a_goal_reports_none(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertNull($rapor->payload['goal_minutes']);
        $this->assertFalse($rapor->goalMet());
    }

    public function test_the_report_carries_the_plan_completion(): void
    {
        $ogrenci = $this->ogrenci();
        $yonetici = $this->yonetici();

        foreach (['Bir' => 'done', 'İki' => 'done', 'Üç' => 'open'] as $baslik => $durum) {
            StudyPlanItem::create([
                'student_id' => $ogrenci->id,
                'title' => $baslik,
                'period' => PlanPeriod::Week->value,
                'week_start' => self::HAFTA,
                'status' => $durum,
                'created_by' => $yonetici->id,
            ]);
        }

        $this->haftaSonrasi();
        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame(2, $rapor->payload['plan_done']);
        $this->assertSame(3, $rapor->payload['plan_total']);
    }

    public function test_the_report_lists_the_exams_of_that_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->deneme($ogrenci, '2026-09-19', 30, 8);   // net 28
        $this->deneme($ogrenci, '2026-09-26', 40, 0);   // sonraki hafta, girmemeli
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertCount(1, $rapor->payload['exams']);
        // payload JSON: tam sayili bir net (28.0) donuste int olarak geliyor.
        // Onemli olan DEGER; ekranda number_format ikisini de ayni basiyor.
        $this->assertSame(28.0, (float) $rapor->payload['exams'][0]['net']);
    }

    /** Net degisimi, o haftanin son denemesi ile ONCEKI deneme arasinda. */
    public function test_the_net_change_compares_with_the_previous_exam(): void
    {
        $ogrenci = $this->ogrenci();
        $this->deneme($ogrenci, '2026-09-07', 20, 0);   // net 20, onceki hafta
        $this->deneme($ogrenci, '2026-09-19', 30, 8);   // net 28, bu hafta
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);

        $this->assertSame(8.0, (float) $rapor->payload['net_change']);
    }

    public function test_a_first_exam_has_no_net_change(): void
    {
        $ogrenci = $this->ogrenci();
        $this->deneme($ogrenci, '2026-09-19', 30, 8);
        $this->haftaSonrasi();

        $this->assertNull($this->uretici()->for($ogrenci, self::HAFTA)->payload['net_change']);
    }

    // --- Koc yorumu ----------------------------------------------------------

    public function test_a_coach_writes_the_comment(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->actingAs($koc)
            ->post(route('coach.report.comment', [$ogrenci, 'hafta' => self::HAFTA]), [
                'coach_comment' => 'Tempo iyi, deneme sayısını artıralım.',
            ])
            ->assertRedirect();

        $this->assertSame('Tempo iyi, deneme sayısını artıralım.', WeeklyReport::sole()->coach_comment);
    }

    public function test_a_coach_cannot_comment_on_an_unassigned_student(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $this->oturum($baskasinin, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->actingAs($koc)
            ->post(route('coach.report.comment', [$baskasinin, 'hafta' => self::HAFTA]), [
                'coach_comment' => 'İzinsiz',
            ])
            ->assertForbidden();
    }

    /**
     * Yeniden hesaplama YORUMU KORUR.
     *
     * Onaylar gecikirse rapor eksik uretilmis olabilir; yonetici yeniden
     * hesaplatabilmeli. Ama kocun yazdigi yorum insan emegi - sayilarla
     * birlikte silinmesi kabul edilemez.
     */
    public function test_regenerating_keeps_the_coach_comment(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);
        $rapor->update(['coach_comment' => 'Elle yazılmış yorum']);

        // Gecikmis onay: oturum rapordan sonra onaylandi.
        $this->oturum($ogrenci, '2026-09-16', 180);

        $yeni = $this->uretici()->regenerate($ogrenci, self::HAFTA);

        $this->assertSame($rapor->id, $yeni->id);
        $this->assertSame(300, $yeni->payload['minutes']);
        $this->assertSame('Elle yazılmış yorum', $yeni->coach_comment);
    }

    // --- Kim gorur -----------------------------------------------------------

    public function test_a_parent_sees_the_report_of_their_child(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = $this->veli($ogrenci);
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $rapor = $this->uretici()->for($ogrenci, self::HAFTA);
        $rapor->update(['coach_comment' => 'Tempo iyi gidiyor.']);

        $this->actingAs($veli)
            ->get(route('parent.report', [$ogrenci, 'hafta' => self::HAFTA]))
            ->assertOk()
            ->assertSee('Tempo iyi gidiyor.');
    }

    public function test_a_parent_cannot_see_another_students_report(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $this->oturum($baskasinin, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->actingAs(User::factory()->parent()->create())
            ->get(route('parent.report', [$baskasinin, 'hafta' => self::HAFTA]))
            ->assertForbidden();
    }

    /** SS6.1-3: veliye giden ogrenciye de gorunur. */
    public function test_a_student_sees_their_own_report(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->uretici()->for($ogrenci, self::HAFTA)->update(['coach_comment' => 'Bu hafta iyiydi.']);

        $this->actingAs($ogrenci)
            ->get(route('user.report', ['hafta' => self::HAFTA]))
            ->assertOk()
            ->assertSee('Bu hafta iyiydi.');
    }

    /** Suren haftaya gidilirse sayfa 500 vermez, sebebini yazar. */
    public function test_an_unfinished_week_explains_itself(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);
        $this->travelTo(Carbon::parse('2026-09-16 10:00', config('kafe.timezone')));

        $this->actingAs($veli)
            ->get(route('parent.report', [$ogrenci, 'hafta' => self::HAFTA]))
            ->assertOk()
            ->assertSee('Hafta tamamlanınca');
    }

    /**
     * Hafta verilmezse TAMAMLANMIS SON hafta acilir.
     *
     * Suren haftaya varsayilmak, raporu acan velinin her seferinde "henuz
     * hazir degil" gormesi demekti - ozellik kullanilmaz gorunurdu.
     */
    public function test_the_default_week_is_the_last_finished_one(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->actingAs($veli)->get(route('parent.report', $ogrenci))->assertOk();

        $this->assertSame(self::HAFTA, WeeklyReport::sole()->week_start->toDateString());
    }

    /**
     * Gecikmis onaydan sonra yonetici raporu yeniden hesaplatabilmeli.
     *
     * Rapor bilerek dondurulmus; tek kacis yolu ACIK bir islem olmali,
     * sessiz bir yeniden hesap degil.
     */
    public function test_a_coach_can_recalculate_after_a_late_approval(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $this->oturum($ogrenci, '2026-09-15', 120);
        $this->haftaSonrasi();

        $this->uretici()->for($ogrenci, self::HAFTA);
        $this->oturum($ogrenci, '2026-09-16', 180);

        $this->actingAs($koc)
            ->post(route('coach.report.regenerate', [$ogrenci, 'hafta' => self::HAFTA]))
            ->assertRedirect();

        $this->assertSame(300, WeeklyReport::sole()->payload['minutes']);
    }
}
