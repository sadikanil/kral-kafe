<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudentParent;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 13 - Haftalik calisma plani.
 *
 * Haftalik HEDEF (Dalga 5) "ne kadar", plan "NE" sorusunu cevapliyor. Ikisi
 * birlikte anlamli: 20 saat calisip hic matematik yapmamak bugun gorunmuyor.
 *
 * Plani yonetici belirliyor (karar 10). Dalga 14'te koc da yaziyor ve
 * form /koc/plan altina tasindi; buradaki testler o adrese bakar.
 * Koc yetkisinin kendisi CoachPlanTest'te.
 */
class StudyPlanTest extends TestCase
{
    use RefreshDatabase;

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

    /** Pazartesi baslangicli hafta - 2026-09-14 pazartesi. */
    private function haftayaGit(string $gun = '2026-09-16 12:00'): void
    {
        $this->travelTo(Carbon::parse($gun, config('kafe.timezone')));
    }

    private function madde(User $ogrenci, string $baslik, ?string $hafta = null): StudyPlanItem
    {
        return StudyPlanItem::create([
            'student_id' => $ogrenci->id,
            'title' => $baslik,
            'week_start' => $hafta ?? LocalDay::weekStart(LocalDay::today()),
            // Dalga 30c: takvim gune bakar; eski maddeler haftanin pazartesinde.
            'plan_date' => $hafta ?? LocalDay::weekStart(LocalDay::today()),
            'created_by' => $this->yonetici()->id,
        ]);
    }

    /**
     * weekStart YEREL pazartesiyi vermeli.
     *
     * weekBounds UTC Carbon donuyor; ondan dogrudan toDateString() almak,
     * yerel pazartesi 00:00'in UTC'de PAZAR 21:00 olmasi yuzunden bir gun
     * geri kayardi. Ayni tuzagin dorduncu bicimi (bkz. SS10.1).
     */
    public function test_the_week_starts_on_the_local_monday(): void
    {
        // 2026-09-16 carsamba; haftanin pazartesisi 14'u.
        $this->assertSame('2026-09-14', LocalDay::weekStart('2026-09-16'));
        $this->assertSame('2026-09-14', LocalDay::weekStart('2026-09-14'));
        $this->assertSame('2026-09-14', LocalDay::weekStart('2026-09-20'));
    }

    // --- Plan olusturma -----------------------------------------------------

    public function test_an_admin_adds_an_item_to_the_students_week(): void
    {
        $ogrenci = $this->ogrenci();
        $ders = Subject::create(['name' => 'Matematik', 'exam_type' => 'tyt']);
        $this->haftayaGit();

        $this->actingAs($this->yonetici())
            ->post(route('coach.plan.store', $ogrenci), [
                'title' => 'Türev 40 soru',
                'subject_id' => $ders->id,
                'plan_date' => '2026-09-16',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('study_plan_items', [
            'student_id' => $ogrenci->id,
            'title' => 'Türev 40 soru',
            'status' => 'open',
        ]);
    }

    public function test_a_student_cannot_add_an_item(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();

        $this->actingAs($ogrenci)
            ->post(route('coach.plan.store', $ogrenci), [
                'title' => 'Kendime görev',
                'period' => 'week',
            ])
            ->assertForbidden();
    }

    // --- Tamamlama ----------------------------------------------------------

    public function test_a_student_marks_their_own_item_done(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $madde = $this->madde($ogrenci, 'Paragraf 30 soru');

        $this->actingAs($ogrenci)
            ->post(route('user.study-plan.complete', $madde))
            ->assertRedirect();

        $madde->refresh();

        $this->assertSame('done', $madde->status);
        $this->assertNotNull($madde->completed_at);
    }

    public function test_marking_done_again_does_not_move_the_completion_time(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $madde = $this->madde($ogrenci, 'Paragraf 30 soru');

        $this->actingAs($ogrenci)->post(route('user.study-plan.complete', $madde));
        $ilkAn = $madde->fresh()->completed_at;

        $this->travelTo(Carbon::parse('2026-09-16 18:00', config('kafe.timezone')));
        $this->actingAs($ogrenci)->post(route('user.study-plan.complete', $madde));

        $this->assertEquals($ilkAn, $madde->fresh()->completed_at);
    }

    public function test_a_student_cannot_touch_another_students_item(): void
    {
        $sahibi = $this->ogrenci('Sahibi');
        $baskasi = $this->ogrenci('Başkası');
        $this->haftayaGit();
        $madde = $this->madde($sahibi, 'Türev 40 soru');

        $this->actingAs($baskasi)
            ->post(route('user.study-plan.complete', $madde))
            ->assertForbidden();

        $this->assertSame('open', $madde->fresh()->status);
    }

    // --- Tamamlama orani ----------------------------------------------------

    public function test_the_completion_rate_counts_only_this_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();

        $buHafta = LocalDay::weekStart(LocalDay::today());
        $gecenHafta = Carbon::parse($buHafta, LocalDay::timezone())->subWeek()->toDateString();

        $this->madde($ogrenci, 'Bu hafta 1');
        $this->madde($ogrenci, 'Bu hafta 2')->update(['status' => 'done', 'completed_at' => now()]);
        $this->madde($ogrenci, 'Geçen hafta', $gecenHafta);

        $this->assertSame([1, 2], StudyPlanItem::weeklyProgress($ogrenci, $buHafta));
    }

    /**
     * GECMIS HAFTA YENIDEN YAZILMAZ. Madde haftasina bagli; plani
     * degistirmek gecmis haftanin "tuttu mu" cevabini degistirmemeli -
     * study_goals'ta verilen kararin aynisi (Dalga 5).
     */
    public function test_a_new_week_starts_empty(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $this->madde($ogrenci, 'Bu hafta');

        $sonrakiHafta = Carbon::parse(LocalDay::weekStart(LocalDay::today()), LocalDay::timezone())
            ->addWeek()->toDateString();

        $this->assertSame([0, 0], StudyPlanItem::weeklyProgress($ogrenci, $sonrakiHafta));
    }

    // --- Kim gorur ----------------------------------------------------------

    public function test_a_student_sees_this_weeks_plan(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $this->madde($ogrenci, 'Türev 40 soru');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Türev 40 soru');
    }

    public function test_a_parent_sees_the_completion_rate(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $this->haftayaGit();
        $this->madde($ogrenci, 'Bir');
        $this->madde($ogrenci, 'İki')->update(['status' => 'done', 'completed_at' => now()]);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('1 / 2');
    }
}
