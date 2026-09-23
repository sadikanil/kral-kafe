<?php

namespace Tests\Feature;

use App\Enums\PlanPeriod;
use App\Enums\Role;
use App\Models\StudentParent;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 14 - Koc rolu ve calisma plani sayfasi.
 *
 * Dalga 13'te plani yalnizca YONETICI belirliyordu ve form ogrenci duzenleme
 * ekranina sikismisti. Koc rolu aktiflesince plan kendi sayfasina cikti:
 * koc kendi ogrencilerini listeler, donemi secer, maddeleri yonetir.
 *
 * Yonetici ayni zamanda koctur (karar 11) - ayri bir koc hesabi acmak
 * zorunda degil, ayni sayfaya girer ve TUM ogrencileri gorur.
 *
 * Plan veliye de acik (karar 2): profile islenen her sey veliye gorunur.
 */
class CoachPlanTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    private function koc(string $ad = 'Koç'): User
    {
        return User::factory()->create(['name' => $ad, 'role' => Role::Coach->value]);
    }

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->withPackage(\App\Models\Package::factory()->tier3())->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    /** 2026-09-16 carsamba: haftanin pazartesisi 14'u, ayin ilki 1'i. */
    private function haftayaGit(string $gun = '2026-09-16 12:00'): void
    {
        $this->travelTo(Carbon::parse($gun, config('kafe.timezone')));
    }

    private function madde(User $ogrenci, string $baslik, PlanPeriod $donem = PlanPeriod::Week, ?string $tarih = null): StudyPlanItem
    {
        return StudyPlanItem::create([
            'student_id' => $ogrenci->id,
            'title' => $baslik,
            'period' => $donem->value,
            'week_start' => $donem->startFor($tarih ?? '2026-09-16'),
            // Dalga 30c: takvim gune bakar; eski maddeler donemin ilk gununde.
            'plan_date' => $donem->startFor($tarih ?? '2026-09-16'),
            'created_by' => $this->yonetici()->id,
        ]);
    }

    // --- Kim girer ----------------------------------------------------------

    /**
     * Koc YALNIZCA kendine atanmis ogrencileri gorur.
     *
     * accessibleStudentIds() bu ana kadar koc icin bos dizi donuyordu;
     * atama tablosu gelince orasi genisledi. Sinirin tek kaynagi orasi
     * oldugu icin liste, tekil kayit ve veli paneli birlikte dogru kalir.
     */
    public function test_a_coach_only_sees_their_assigned_students(): void
    {
        $koc = $this->koc();
        $benim = $this->ogrenci('Benim Öğrencim');
        $baskasinin = $this->ogrenci('Başkasının Öğrencisi');

        $koc->coachStudents()->attach($benim->id);

        $this->actingAs($koc)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('Benim Öğrencim')
            ->assertDontSee('Başkasının Öğrencisi');
    }

    public function test_a_coach_cannot_open_an_unassigned_students_plan(): void
    {
        $koc = $this->koc();
        $baskasinin = $this->ogrenci('Başkasının Öğrencisi');

        $this->actingAs($koc)->get(route('coach.plan.show', $baskasinin))
            ->assertForbidden();
    }

    /** Karar 11: yonetici en yetkili kisidir ve koc yetkilerini de tasir. */
    public function test_an_admin_sees_every_student_without_being_assigned(): void
    {
        $bir = $this->ogrenci('Birinci');
        $iki = $this->ogrenci('İkinci');

        $this->actingAs($this->yonetici())->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('Birinci')
            ->assertSee('İkinci');
    }

    public function test_a_student_cannot_open_the_coach_page(): void
    {
        $this->actingAs($this->ogrenci())->get(route('coach.plan.index'))
            ->assertForbidden();
    }

    public function test_a_parent_cannot_open_the_coach_page(): void
    {
        $this->actingAs(User::factory()->parent()->create())
            ->get(route('coach.plan.index'))
            ->assertForbidden();
    }

    /**
     * Kocun giristen sonra inecegi yer.
     *
     * Bu ana kadar koc 'user.dashboard'a dusuyordu - ogrenci panosu, kocun
     * isine yaramayan ve kendi verisi olmayan bir ekran. Role::homeRoute
     * tek dogruluk kaynagi (SS12'de iki yere yazilip ayrisma tuzagi).
     */
    public function test_a_coach_lands_on_the_plan_page(): void
    {
        $this->assertSame('coach.plan.index', Role::Coach->homeRoute());
    }

    /**
     * Liste, kocun "kim geride" sorusunu tek bakista cevaplamali.
     *
     * Sayim TEK toplu sorguyla yapiliyor; ogrenci basina ayri sorgu N+1
     * olurdu ve fonksiyon-veritabani mesafesi yuzunden bu projede pahaliya
     * mal olurdu. Aylik madde haftalik sayima GIRMEMELI.
     */
    public function test_the_student_list_shows_this_weeks_progress(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci('Ali');
        $koc->coachStudents()->attach($ogrenci->id);
        $this->haftayaGit();

        $this->madde($ogrenci, 'Bir');
        $this->madde($ogrenci, 'İki')->update(['status' => 'done', 'completed_at' => now()]);
        $this->madde($ogrenci, 'Aylık hedef', PlanPeriod::Month);

        $this->actingAs($koc)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('1 / 2');
    }

    // --- Madde ekleme -------------------------------------------------------

    public function test_a_coach_adds_a_weekly_item(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);
        $ders = Subject::create(['name' => 'Matematik', 'exam_type' => 'tyt']);
        $this->haftayaGit();

        $this->actingAs($koc)
            ->post(route('coach.plan.store', $ogrenci), [
                'title' => 'Türev 40 soru',
                'subject_id' => $ders->id,
                'plan_date' => '2026-09-16',
            ])
            ->assertRedirect();

        $madde = StudyPlanItem::where('title', 'Türev 40 soru')->sole();

        $this->assertSame($ogrenci->id, $madde->student_id);
        $this->assertSame($koc->id, $madde->created_by);
        $this->assertSame(PlanPeriod::Week, $madde->period);
        // Ham sutun degeri surucuye gore degisir (SQLite tarihe 00:00:00
        // ekler); model uzerinden okumak ikisinde de ayni cevabi verir.
        $this->assertSame('2026-09-14', $madde->week_start->toDateString());
    }

    /**
     * Aylik madde ayin ILK gunune dosyalanir.
     *
     * Ay basi UTC'de bir onceki aya dustugu icin burasi LocalDay::monthStart
     * uzerinden gecmek zorunda; ham Carbon ile yazilsa "Eylul plani"
     * Agustos'a giderdi.
     */
    public function test_a_coach_cannot_add_an_item_for_an_unassigned_student(): void
    {
        $koc = $this->koc();
        $baskasinin = $this->ogrenci('Başkasının');
        $this->haftayaGit();

        $this->actingAs($koc)
            ->post(route('coach.plan.store', $baskasinin), [
                'title' => 'İzinsiz görev',
                'period' => 'week',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('study_plan_items', 0);
    }

    /** Dalga 30c: plan gunlere bagli; gunsuz madde olmaz. */
    public function test_a_plan_date_is_required(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);
        $this->haftayaGit();

        $this->actingAs($koc)
            ->post(route('coach.plan.store', $ogrenci), ['title' => 'Yıllık hedef'])
            ->assertSessionHasErrors('plan_date');
    }

    // --- Donem ayrimi -------------------------------------------------------

    /**
     * TUZAK: haftalik ve aylik madde AYNI tarihi tasiyabilir.
     *
     * week_start tek sutun; haftalik madde pazartesiyi, aylik madde ayin
     * 1'ini tutar. Ayin 1'i pazartesiye denk geldiginde (or. 2026-06-01)
     * ikisi ayni degeri tasir. Donem suzgeci olmazsa aylik hedefler
     * haftalik listeye sizar ve tamamlama orani bozulur.
     */
    public function test_a_monthly_item_does_not_leak_into_the_week_that_starts_the_month(): void
    {
        $ogrenci = $this->ogrenci();

        // 2026-06-01 PAZARTESI: haftanin da ayin da ilk gunu.
        $this->madde($ogrenci, 'Haftalık madde', PlanPeriod::Week, '2026-06-01');
        $this->madde($ogrenci, 'Aylık madde', PlanPeriod::Month, '2026-06-01');

        $haftalik = StudyPlanItem::forPeriod($ogrenci, PlanPeriod::Week, '2026-06-01')->get();
        $aylik = StudyPlanItem::forPeriod($ogrenci, PlanPeriod::Month, '2026-06-01')->get();

        $this->assertSame(['Haftalık madde'], $haftalik->pluck('title')->all());
        $this->assertSame(['Aylık madde'], $aylik->pluck('title')->all());
    }

    public function test_the_weekly_progress_ignores_monthly_items(): void
    {
        $ogrenci = $this->ogrenci();

        $this->madde($ogrenci, 'Haftalık', PlanPeriod::Week, '2026-06-01');
        $this->madde($ogrenci, 'Aylık bir', PlanPeriod::Month, '2026-06-01');
        $this->madde($ogrenci, 'Aylık iki', PlanPeriod::Month, '2026-06-01');

        $this->assertSame([0, 1], StudyPlanItem::weeklyProgress($ogrenci, '2026-06-01'));
        $this->assertSame([0, 2], StudyPlanItem::progress($ogrenci, PlanPeriod::Month, '2026-06-01'));
    }

    // --- Silme --------------------------------------------------------------

    public function test_a_coach_deletes_an_item_of_their_own_student(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);
        $madde = $this->madde($ogrenci, 'Silinecek');

        $this->actingAs($koc)
            ->delete(route('coach.plan.destroy', $madde))
            ->assertRedirect();

        $this->assertDatabaseCount('study_plan_items', 0);
    }

    public function test_a_coach_cannot_delete_an_unassigned_students_item(): void
    {
        $koc = $this->koc();
        $baskasinin = $this->ogrenci('Başkasının');
        $madde = $this->madde($baskasinin, 'Dokunulmaz');

        $this->actingAs($koc)
            ->delete(route('coach.plan.destroy', $madde))
            ->assertForbidden();

        $this->assertDatabaseCount('study_plan_items', 1);
    }

    // --- Koc atamasi --------------------------------------------------------

    public function test_an_admin_assigns_a_coach_to_a_student(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici())
            ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id])
            ->assertRedirect();

        $this->assertTrue($koc->fresh()->coachStudents()->where('users.id', $ogrenci->id)->exists());
    }

    /** Ayni atamayi iki kez gondermek ikinci satiri olusturmamali. */
    public function test_assigning_the_same_coach_twice_does_not_duplicate(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->yonetici())
                ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id]);
        }

        $this->assertDatabaseCount('coach_assignments', 1);
    }

    public function test_a_coach_cannot_assign_themselves_a_student(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();

        $this->actingAs($koc)
            ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id])
            ->assertForbidden();

        $this->assertDatabaseCount('coach_assignments', 0);
    }

    /** Ogrenci koc olarak atanamaz - rol kontrolu yazma ucunda. */
    public function test_only_a_coach_or_admin_can_be_assigned_as_coach(): void
    {
        $ogrenci = $this->ogrenci();
        $baskaOgrenci = $this->ogrenci('Diğer');

        $this->actingAs($this->yonetici())
            ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $baskaOgrenci->id])
            ->assertSessionHasErrors('coach_id');
    }

    /**
     * ?baslangic= kullanicinin elinde; bozuk deger 500 vermemeli.
     *
     * startFor() ham Carbon::parse cagiriyor ve cop bir degerde firlatir.
     * Sayfa bir raporlama ekrani; taninmayan tarih bugune dusmeli.
     */
    public function test_a_junk_start_date_falls_back_to_today(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);
        $this->haftayaGit();
        $this->madde($ogrenci, 'Bu haftanın maddesi');

        $this->actingAs($koc)
            ->get(route('coach.plan.show', [$ogrenci, 'hafta' => 'kedi']))
            ->assertOk()
            ->assertSee('Bu haftanın maddesi');
    }

    public function test_an_admin_removes_a_coach_from_a_student(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);

        $this->actingAs($this->yonetici())
            ->delete(route('admin.coaches.detach', [$ogrenci, $koc]))
            ->assertRedirect();

        $this->assertDatabaseCount('coach_assignments', 0);
    }

    /** Atama kalkinca koc o ogrenciyi ARTIK goremez. */
    public function test_removing_the_assignment_closes_the_coachs_access(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);
        $koc->coachStudents()->detach($ogrenci->id);

        $this->actingAs($koc)->get(route('coach.plan.show', $ogrenci))
            ->assertForbidden();
    }

    // --- Kim gorur ----------------------------------------------------------

    /** Karar 2: profile islenen her sey veliye acik. */
    /** Dalga 30c: veli haftanin takvimini gorur (aylik donem kalkti). */
    public function test_a_parent_sees_the_weeks_plan(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $this->haftayaGit();
        $this->madde($ogrenci, 'Bu hafta türev');

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Bu hafta türev');
    }

    /**
     * YAN ETKI, BILEREK ACIK BIRAKILDI.
     *
     * accessibleStudentIds()'i koc icin genisletmek UserPolicy ve
     * ExamReportPolicy'yi de genisletti - ikisi de oraya delege ediyor.
     * Yani koc, atandigi ogrencinin deneme raporunu da gorebiliyor.
     *
     * Bu urun niyetine uygun (SS7-B: "Kim gorur: ogrenci, veli, KOC,
     * yonetici") ama kazara acilmis bir yetki test edilmeden birakilmaz.
     * Asil onemlisi ikinci test: sinirin atamaya bagli oldugunu pinliyor.
     */
    public function test_a_coach_can_open_their_own_students_exam_report(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci();
        $koc->coachStudents()->attach($ogrenci->id);

        $rapor = \App\Models\ExamReport::factory()->analyzed()->create([
            'student_id' => $ogrenci->id,
        ]);

        $this->actingAs($koc)->get(route('user.exam-reports.show', $rapor))->assertOk();
    }

    public function test_a_coach_cannot_open_an_unassigned_students_exam_report(): void
    {
        $koc = $this->koc();
        $baskasinin = $this->ogrenci('Başkasının');

        $rapor = \App\Models\ExamReport::factory()->analyzed()->create([
            'student_id' => $baskasinin->id,
        ]);

        $this->actingAs($koc)->get(route('user.exam-reports.show', $rapor))->assertForbidden();
    }

    public function test_a_student_sees_the_monthly_plan_on_the_dashboard(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $this->madde($ogrenci, 'Bu ay 8 deneme', PlanPeriod::Month);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Bu ay 8 deneme');
    }

    public function test_a_student_marks_a_monthly_item_done(): void
    {
        $ogrenci = $this->ogrenci();
        $this->haftayaGit();
        $madde = $this->madde($ogrenci, 'Bu ay 8 deneme', PlanPeriod::Month);

        $this->actingAs($ogrenci)
            ->post(route('user.study-plan.complete', $madde))
            ->assertRedirect();

        $this->assertSame('done', $madde->fresh()->status);
    }
}
