<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudentParent;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\DeclineSignals;
use App\Services\StudyStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 16 - Devamlilik dusus sinyalleri.
 *
 * KURAL TABANLI VE YORUMSUZ (SS6.1-6): sistem sayi gosterir, sifat uretmez.
 * "Motivasyonu dusuk" demez, "son 7 gunde 2 gelis, onceki 7 gunde 5" der.
 *
 * ONCE KOCA GIDER (SS6.1-5). Veli panelinde YOKTUR: ham bir dusus sinyali
 * veliye dogrudan gitseydi, koc daha bakmadan evde tartisma baslardi.
 * Veliye giden sey kocun yorumu (Dalga 15a raporu).
 *
 * Dalga 11'in gun sonu devamsizlik bildiriminden farki: o TEK GUNE bakar ve
 * veliye gider, bu EGILIME bakar ve kocta kalir.
 *
 * Tablo yok: sinyal saklanmaz, her bakista hesaplanir. Saklamak "ne zaman
 * duzeldi" sorusunu da yonetmeyi gerektirirdi.
 */
class DeclineSignalTest extends TestCase
{
    use RefreshDatabase;

    /** Bugun: 22 Eylul 2026 sali. */
    private function bugun(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22 10:00', config('kafe.timezone')));
    }

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function gelis(User $ogrenci, string $gun, int $dakika = 120): StudySession
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

    private function sinyaller(User $ogrenci): array
    {
        return app(DeclineSignals::class)->for($ogrenci);
    }

    private function turler(User $ogrenci): array
    {
        return array_map(fn ($s) => $s->kind, $this->sinyaller($ogrenci));
    }

    // --- Gelis dususu --------------------------------------------------------

    public function test_fewer_visits_than_the_week_before_raises_a_signal(): void
    {
        $ogrenci = $this->ogrenci();

        // Onceki 7 gun (09-15 Eylul): 5 gelis
        foreach (['2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-14'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }
        // Son 7 gun (16-22 Eylul): 2 gelis
        foreach (['2026-09-17', '2026-09-19'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }

        $this->bugun();

        $this->assertContains('attendance', $this->turler($ogrenci));
        $this->assertStringContainsString('2 geliş', $this->sinyaller($ogrenci)[0]->label);
    }

    /** Kucuk dalgalanma sinyal degildir - gurultuye donerdi. */
    public function test_a_single_day_drop_is_not_a_signal(): void
    {
        $ogrenci = $this->ogrenci();

        foreach (['2026-09-09', '2026-09-10', '2026-09-11'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }
        foreach (['2026-09-17', '2026-09-18'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }

        $this->bugun();

        $this->assertNotContains('attendance', $this->turler($ogrenci));
    }

    public function test_more_visits_than_before_raises_nothing(): void
    {
        $ogrenci = $this->ogrenci();

        $this->gelis($ogrenci, '2026-09-10');
        foreach (['2026-09-17', '2026-09-18', '2026-09-19', '2026-09-21'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }

        $this->bugun();

        $this->assertSame([], $this->turler($ogrenci));
    }

    /** Onayi bekleyen oturum gelis SAYILMAZ - tum istatistikler gibi. */
    public function test_an_unapproved_session_does_not_count_as_a_visit(): void
    {
        $ogrenci = $this->ogrenci();

        foreach (['2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-14'] as $gun) {
            $this->gelis($ogrenci, $gun);
        }
        foreach (['2026-09-17', '2026-09-18', '2026-09-19', '2026-09-21'] as $gun) {
            $this->gelis($ogrenci, $gun)->update(['approval_status' => ApprovalStatus::Pending->value]);
        }

        $this->bugun();

        $this->assertContains('attendance', $this->turler($ogrenci));
    }

    // --- Devamsizlik serisi --------------------------------------------------

    public function test_not_coming_for_three_days_raises_a_signal(): void
    {
        $ogrenci = $this->ogrenci();
        $this->gelis($ogrenci, '2026-09-18');   // son gelis, 4 gun once

        $this->bugun();

        $this->assertContains('absence', $this->turler($ogrenci));
    }

    public function test_coming_yesterday_raises_nothing(): void
    {
        $ogrenci = $this->ogrenci();
        $this->gelis($ogrenci, '2026-09-21');

        $this->bugun();

        $this->assertNotContains('absence', $this->turler($ogrenci));
    }

    /**
     * HIC GELMEMIS ogrenci icin devamsizlik sinyali YOK.
     *
     * Yeni kayit olmus ogrenci "dususte" degil, henuz baslamamis. Sinyal
     * gurultuye donerse koc onlara bakmayi birakir ve ozellik oldu demektir.
     */
    public function test_a_student_who_never_came_raises_no_absence_signal(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bugun();

        $this->assertNotContains('absence', $this->turler($ogrenci));
    }

    // --- Hedefin altinda -----------------------------------------------------

    /**
     * Hedef sinyali TAMAMLANMIS haftaya bakar.
     *
     * Suren haftaya bakilsaydi her ogrenci pazartesi sabahi "hedefinin
     * %0'i" diye isaretlenirdi - haftalik raporun suren hafta kararinin
     * aynisi (Dalga 15a).
     */
    public function test_falling_far_below_the_goal_raises_a_signal(): void
    {
        $ogrenci = $this->ogrenci();
        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);

        // Tamamlanmis hafta 14-20 Eylul: 120 dakika = hedefin %20'si
        $this->gelis($ogrenci, '2026-09-15', 120);

        $this->bugun();

        $this->assertContains('goal', $this->turler($ogrenci));
    }

    public function test_meeting_the_goal_raises_nothing(): void
    {
        $ogrenci = $this->ogrenci();
        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);

        $this->gelis($ogrenci, '2026-09-15', 300);
        $this->gelis($ogrenci, '2026-09-16', 300);

        $this->bugun();

        $this->assertNotContains('goal', $this->turler($ogrenci));
    }

    public function test_a_student_without_a_goal_raises_no_goal_signal(): void
    {
        $ogrenci = $this->ogrenci();
        $this->gelis($ogrenci, '2026-09-15', 30);

        $this->bugun();

        $this->assertNotContains('goal', $this->turler($ogrenci));
    }

    // --- Kim gorur -----------------------------------------------------------

    public function test_a_coach_sees_the_signal_on_the_student_list(): void
    {
        $ogrenci = $this->ogrenci('Düşüşteki');
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        $this->gelis($ogrenci, '2026-09-18');
        $this->bugun();

        $this->actingAs($koc)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('gündür gelmedi');
    }

    /**
     * VELI DUSUS SINYALINI GORMEZ (SS6.1-5).
     *
     * Ham sinyal veliye dogrudan gitseydi, koc daha bakmadan evde tartisma
     * baslardi. Veliye giden sey kocun YORUMU (Dalga 15a raporu).
     */
    public function test_a_parent_never_sees_a_raw_signal(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $this->gelis($ogrenci, '2026-09-18');
        $this->bugun();

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertDontSee('gündür gelmedi');

        $this->actingAs($veli)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertDontSee('gündür gelmedi');
    }

    public function test_a_student_does_not_see_the_raw_signal_either(): void
    {
        $ogrenci = $this->ogrenci();
        $this->gelis($ogrenci, '2026-09-18');
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('gündür gelmedi');
    }

    // --- Toplu hesap ---------------------------------------------------------

    /**
     * Liste TEK sorguyla beslenir.
     *
     * attendedDays() gun basina bir sorgu aciyor; 14 gun x N ogrenci koc
     * listesini yuzlerce sorguya cikarirdi. Fonksiyon-veritabani mesafesi bu
     * projede bir kez pahaliya mal oldu (SS10.12).
     */
    public function test_the_bulk_lookup_matches_the_single_student_one(): void
    {
        $bir = $this->ogrenci('Bir');
        $iki = $this->ogrenci('İki');

        $this->gelis($bir, '2026-09-17');
        $this->gelis($bir, '2026-09-19');
        $this->gelis($iki, '2026-09-18');

        $this->bugun();

        $istatistik = app(StudyStats::class);
        $toplu = $istatistik->attendedDaysForMany([$bir->id, $iki->id], '2026-09-16', '2026-09-22');

        $this->assertSame($istatistik->attendedDays($bir, '2026-09-16', '2026-09-22'), $toplu[$bir->id]);
        $this->assertSame($istatistik->attendedDays($iki, '2026-09-16', '2026-09-22'), $toplu[$iki->id]);
        $this->assertSame(['2026-09-17', '2026-09-19'], $toplu[$bir->id]);
    }

    /** Gece yarisini asan oturum IKI gune de yazilir - mevcut tanimin aynisi. */
    public function test_a_session_across_midnight_counts_for_both_days(): void
    {
        $ogrenci = $this->ogrenci();

        $bas = Carbon::parse('2026-09-17 22:00', config('kafe.timezone'));
        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa'])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addMinutes(210)->utc(),   // 01:30
            'duration_minutes' => 210,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);

        $this->bugun();

        $toplu = app(StudyStats::class)->attendedDaysForMany([$ogrenci->id], '2026-09-16', '2026-09-22');

        $this->assertSame(['2026-09-17', '2026-09-18'], $toplu[$ogrenci->id]);
    }
}
