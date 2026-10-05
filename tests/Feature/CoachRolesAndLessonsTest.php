<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\PrivateLessonSlot;
use App\Models\StudyPlanItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WeakTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Koclar, odev ve ozel ders (1 Ekim 2026).
 *
 *  - Veli ayni zamanda koc olabilir (is_coach): veli panelinde yalnizca
 *    kendi cocugu, koc sayfalarinda yalnizca atanan ogrenciler. Atanan
 *    ogrencinin odemeleri veli panelinden acilmaz.
 *  - Yonetici (Cahit Hoca) disindaki koclar plana yalnizca "odev" ekler,
 *    yalnizca kendi ekledigini tasir/siler.
 *  - Ozel ders paketten bagimsiz; dersi veren koc kendi derslerini gorur,
 *    iptal eder, tasir, saatini degistirir. Ekleme, silme, paket, ucret ve
 *    odeme yalnizca yoneticide.
 */
class CoachRolesAndLessonsTest extends TestCase
{
    use RefreshDatabase;

    private User $cahit;
    private User $ibrahim;
    private User $oglu;
    private User $ogrenci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 10:00', config('kafe.timezone')));

        $this->cahit = User::factory()->admin()->create(['name' => 'Cahit Atılğan', 'coach_subject' => 'Fizik']);
        $this->ibrahim = User::factory()->parent()->create(['name' => 'İbrahim Acar', 'is_coach' => true, 'coach_subject' => 'Matematik']);
        $this->oglu = User::factory()->student()->withPackage(Package::factory()->tier1())->create(['name' => 'Ali Acar']);
        $this->ibrahim->students()->attach($this->oglu->id);
        $this->ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Zeynep Kaya', 'grade' => '12', 'field' => 'say']);
        $this->ibrahim->coachStudents()->attach($this->ogrenci->id);
    }

    // --- Cift rol ------------------------------------------------------------

    public function test_a_parent_with_coach_rights_has_both_roles(): void
    {
        $this->assertTrue($this->ibrahim->hasRole(Role::Parent));
        $this->assertTrue($this->ibrahim->hasRole(Role::Coach));
        $this->assertSame('Veli · Koç', $this->ibrahim->rolesLabel());
        $this->assertSame('İbrahim Acar · Matematik', $this->ibrahim->coachLabel());
        $this->assertSame('parent.dashboard', $this->ibrahim->homeRoute());

        // Bayrak yalnizca veli/ogretmende anlamli: ogrenciye koc yetkisi gecmez.
        $ogrenci = User::factory()->student()->create(['is_coach' => true]);
        $this->assertFalse($ogrenci->hasRole(Role::Coach));
    }

    public function test_the_menu_shows_the_parent_and_coaching_groups(): void
    {
        $this->actingAs($this->ibrahim)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee(route('coach.plan.index'), false)
            ->assertSee(route('coach.lessons.index'), false)
            ->assertSee('Çocuklarım');
    }

    public function test_the_parent_panel_lists_only_their_own_child(): void
    {
        $this->actingAs($this->ibrahim)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('Ali Acar')
            ->assertDontSee('Zeynep Kaya');

        $this->actingAs($this->ibrahim)->get(route('parent.student', $this->oglu))->assertOk();
        // Koc olarak atandigi ogrencinin veli sayfasi ve odemeleri kapali.
        $this->actingAs($this->ibrahim)->get(route('parent.student', $this->ogrenci))->assertForbidden();
        $this->actingAs($this->ibrahim)->get(route('parent.payments', $this->ogrenci))->assertForbidden();
    }

    public function test_the_coach_pages_list_only_assigned_students(): void
    {
        $this->actingAs($this->ibrahim)->get(route('coach.plan.index'))
            ->assertOk()
            ->assertSee('Zeynep Kaya')
            ->assertDontSee('Ali Acar');

        $this->actingAs($this->ibrahim)->get(route('coach.plan.show', $this->ogrenci))->assertOk();
        // Kendi cocugunun kocu degil (atanmadikca).
        $this->actingAs($this->ibrahim)->get(route('coach.plan.show', $this->oglu))->assertForbidden();
    }

    public function test_without_the_flag_the_parent_is_not_a_coach(): void
    {
        $this->ibrahim->update(['is_coach' => false]);

        $this->actingAs($this->ibrahim->fresh())->get(route('coach.plan.index'))->assertForbidden();
    }

    public function test_the_admin_saves_coach_rights_and_subject_for_a_parent(): void
    {
        $veli = User::factory()->parent()->create(['name' => 'Ayla Demir', 'phone' => '5551112233']);
        $veli->students()->attach($this->oglu->id);

        $this->actingAs($this->cahit)->put(route('admin.users.update', $veli), [
            'name' => 'Ayla Demir', 'phone' => '0555 111 22 33', 'role' => 'parent',
            'subscription_status' => 'active', 'is_coach' => '1', 'coach_subject' => 'Kimya',
            'student_ids' => [$this->oglu->id],
        ])->assertRedirect(route('admin.users.index'));

        $veli->refresh();
        $this->assertTrue($veli->is_coach);
        $this->assertSame('Kimya', $veli->coach_subject);

        // Koc yetkili veli koc olarak atanabilir.
        $this->actingAs($this->cahit)->post(route('admin.coaches.attach', $this->ogrenci), ['coach_id' => $veli->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->ogrenci->coaches()->whereKey($veli->id)->exists());
    }

    /**
     * Canli hata (1 Ekim 2026): ozel ders verdigi ogrenciler veli bagiyla
     * kurulmus, koc sayfalarinda gorunmuyordu. Kocluk listesi ayri.
     */
    public function test_the_admin_moves_lesson_students_from_parent_link_to_coaching(): void
    {
        $sevcan = User::factory()->student()->withPackage(Package::factory()->tier1())->create(['name' => 'Sevcan Karaoğlan']);
        User::factory()->parent()->create()->students()->attach($sevcan->id);
        $this->ibrahim->students()->attach($sevcan->id);

        $this->actingAs($this->cahit)->get(route('admin.users.edit', $this->ibrahim))
            ->assertOk()
            ->assertSee('Velisi olduğu öğrenciler')
            ->assertSee('Koçluk / özel ders verdiği öğrenciler')
            ->assertSee('name="coach_student_ids[]" value="' . $this->ogrenci->id . '"', false);

        $this->actingAs($this->cahit)->put(route('admin.users.update', $this->ibrahim), [
            'name' => 'İbrahim Acar', 'phone' => '0555 999 88 77', 'role' => 'parent', 'subscription_status' => 'active',
            'is_coach' => '1', 'coach_subject' => 'Matematik',
            'student_ids' => [$this->oglu->id],
            'coach_student_ids' => [$this->ogrenci->id, $sevcan->id],
        ])->assertSessionHasNoErrors();

        $this->ibrahim->refresh();
        $this->assertEqualsCanonicalizing([$this->oglu->id], $this->ibrahim->childStudentIds());
        $this->assertEqualsCanonicalizing([$this->ogrenci->id, $sevcan->id], $this->ibrahim->coachableStudentIds());

        $this->actingAs($this->ibrahim)->get(route('coach.plan.index'))
            ->assertOk()->assertSee('Sevcan Karaoğlan')->assertSee('Zeynep Kaya');
        $this->actingAs($this->ibrahim)->get(route('parent.dashboard'))
            ->assertOk()->assertDontSee('Sevcan Karaoğlan');
    }

    public function test_removing_coach_rights_drops_the_coaching_links(): void
    {
        $this->actingAs($this->cahit)->put(route('admin.users.update', $this->ibrahim), [
            'name' => 'İbrahim Acar', 'phone' => '0555 999 88 77', 'role' => 'parent', 'subscription_status' => 'active',
            'is_coach' => '0', 'student_ids' => [$this->oglu->id], 'coach_student_ids' => [$this->ogrenci->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $this->ibrahim->fresh()->coachStudents()->pluck('users.id')->all());
    }

    public function test_the_user_list_shows_coach_instead_of_parent(): void
    {
        $sade = User::factory()->parent()->create(['name' => 'Sade Veli']);

        $this->actingAs($this->cahit)->get(route('admin.users.index', ['role' => 'coach']))
            ->assertOk()
            ->assertSee('İbrahim Acar')
            ->assertSee('Veli · 1 çocuk')
            ->assertSee('1 öğrenci · Matematik')
            ->assertDontSee('Sade Veli');

        $this->assertSame(Role::Coach, $this->ibrahim->displayRole());
        $this->assertSame(Role::Parent, $sade->displayRole());
    }

    /** Ogrenci formunda koc yetkili veli etiketli, koclar bransiyla (5 Ekim 2026). */
    public function test_the_student_form_marks_coach_parents_and_shows_coach_subjects(): void
    {
        User::factory()->parent()->create(['name' => 'Sade Veli']);

        $this->actingAs($this->cahit)->get(route('admin.users.edit', $this->ogrenci))
            ->assertOk()
            ->assertSeeInOrder(['Velileri', 'İbrahim Acar', 'Koç · Matematik'])->assertSee('Sade Veli')
            ->assertSee('Özel ders ya da koçluk verdiği öğrenci için aşağıdaki', false)
            // Koclar karti: atanan koc bransi ve asil roluyle
            ->assertSee('Koç · Matematik · Veli')
            // Atanabilir listede brans
            ->assertSee('Cahit Atılğan · Fizik (Yönetici)');
    }

    public function test_a_student_never_keeps_coach_rights_from_the_form(): void
    {
        $this->actingAs($this->cahit)->put(route('admin.users.update', $this->ogrenci), [
            'name' => 'Zeynep Kaya', 'phone' => '0555 222 33 44', 'role' => 'student', 'subscription_status' => 'active',
            'is_coach' => '1', 'coach_subject' => 'Matematik', 'grade' => '12', 'field' => 'say',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->ogrenci->fresh()->is_coach);
        $this->assertNull($this->ogrenci->fresh()->coach_subject);
    }

    // --- Odev ----------------------------------------------------------------

    public function test_a_coach_only_adds_homework(): void
    {
        $this->actingAs($this->ibrahim)
            ->post(route('coach.plan.store', $this->ogrenci), ['plan_date' => '2026-10-01', 'title' => '40 türev sorusu', 'tag' => 'plan'])
            ->assertSessionHas('success', 'Ödev eklendi.');

        $madde = StudyPlanItem::sole();
        $this->assertSame(StudyPlanItem::TAG_HOMEWORK, $madde->tag);
        $this->assertSame($this->ibrahim->id, $madde->created_by);

        // Ogrenci planinda "Odev" ve kocun adi + bransi.
        $this->actingAs($this->ogrenci)->get(route('user.plan', ['hafta' => '2026-09-28']))
            ->assertOk()
            ->assertSee('Koçların: İbrahim Acar · Matematik')
            ->assertSee('40 türev sorusu')
            ->assertSee('Ödev')
            ->assertSee('İbrahim Acar · Matematik');
    }

    public function test_the_admin_adds_plan_items_and_may_tag_homework(): void
    {
        $this->actingAs($this->cahit)
            ->post(route('coach.plan.store', $this->ogrenci), ['plan_date' => '2026-10-01', 'title' => 'Kuvvet tekrarı'])
            ->assertSessionHas('success', 'Plana eklendi.');
        $this->actingAs($this->cahit)
            ->post(route('coach.plan.store', $this->ogrenci), ['plan_date' => '2026-10-01', 'title' => 'Vektör ödevi', 'tag' => 'homework']);

        $this->assertSame(['plan', 'homework'], StudyPlanItem::orderBy('id')->pluck('tag')->all());
    }

    public function test_a_coach_changes_only_their_own_homework(): void
    {
        $yonetici = StudyPlanItem::create([
            'student_id' => $this->ogrenci->id, 'title' => 'Yöneticinin planı', 'plan_date' => '2026-10-01',
            'week_start' => '2026-09-28', 'created_by' => $this->cahit->id,
        ]);
        $odev = StudyPlanItem::create([
            'student_id' => $this->ogrenci->id, 'title' => 'Türev ödevi', 'plan_date' => '2026-10-01',
            'week_start' => '2026-09-28', 'created_by' => $this->ibrahim->id, 'tag' => 'homework',
        ]);

        $this->actingAs($this->ibrahim)->patch(route('coach.plan.move', $yonetici), ['plan_date' => '2026-10-02'])->assertForbidden();
        $this->actingAs($this->ibrahim)->delete(route('coach.plan.destroy', $yonetici))->assertForbidden();
        $this->actingAs($this->ibrahim)->patch(route('coach.plan.move', $odev), ['plan_date' => '2026-10-02'])->assertRedirect();
        $this->actingAs($this->ibrahim)->delete(route('coach.plan.destroy', $odev))->assertRedirect();

        $this->assertModelExists($yonetici);
        $this->assertModelMissing($odev);

        // Takvimde menu yalnizca degistirebildigi maddede.
        $this->actingAs($this->ibrahim)->get(route('coach.plan.show', [$this->ogrenci, 'hafta' => '2026-09-28']))
            ->assertDontSee(route('coach.plan.destroy', $yonetici), false);
    }

    public function test_a_weak_topic_sent_to_plan_by_a_coach_is_homework(): void
    {
        $konu = WeakTopic::create(['student_id' => $this->ogrenci->id, 'topic' => 'Logaritma']);

        $this->actingAs($this->ibrahim)->post(route('coach.topics.plan', $konu))->assertRedirect();

        $this->assertSame(StudyPlanItem::TAG_HOMEWORK, StudyPlanItem::sole()->tag);
    }

    // --- Ozel ders -----------------------------------------------------------

    public function test_the_admin_adds_one_lesson_to_many_students_and_the_teacher_becomes_their_coach(): void
    {
        $diger = User::factory()->student()->withPackage(Package::factory()->tier1())->create(['name' => 'Mert Can']);

        $this->actingAs($this->cahit)->post(route('admin.lessons.store-many'), [
            'teacher_id' => $this->ibrahim->id, 'branch' => '', 'weekday' => 4,
            'starts_at' => '17:00', 'ends_at' => '18:00',
            'student_ids' => [$this->ogrenci->id, $diger->id],
        ])->assertSessionHas('success', '2 öğrenciye özel ders saati eklendi.');

        $this->assertSame(2, PrivateLessonSlot::count());
        // Ders adi bossa ogretmenin bransi.
        $this->assertSame(['Matematik'], PrivateLessonSlot::pluck('branch')->unique()->values()->all());
        // Paketinde kocluk olmayan ogrenciye de: dersi veren kocu olur.
        $this->assertTrue($diger->coaches()->whereKey($this->ibrahim->id)->exists());

        $this->actingAs($this->cahit)->get(route('admin.lessons.index'))
            ->assertOk()
            ->assertSee('İbrahim Acar · Matematik')
            ->assertSee('Mert Can');
    }

    public function test_the_admin_opens_a_private_lesson_package_for_many_students(): void
    {
        $paket = Package::factory()->create([
            'name' => 'Matematik özel ders', 'monthly_price' => 4000, 'is_addon' => true,
            'includes_private_lessons' => true, 'lesson_count' => 8,
        ]);

        $this->actingAs($this->cahit)->post(route('admin.lessons.package'), [
            'package_id' => $paket->id, 'starts_on' => '2026-10-01',
            'student_ids' => [$this->ogrenci->id, $this->oglu->id],
        ])->assertSessionHas('success');

        $this->assertSame(2, Subscription::where('package_id', $paket->id)->count());
        $this->assertSame('4000.00', (string) Subscription::where('package_id', $paket->id)->first()->price);
    }

    public function test_coaches_cannot_reach_admin_lesson_or_package_pages(): void
    {
        $this->actingAs($this->ibrahim)->get(route('admin.lessons.index'))->assertForbidden();
        $this->actingAs($this->ibrahim)->post(route('admin.lessons.package'), [])->assertForbidden();
        $this->actingAs($this->ibrahim)->post(route('admin.lessons.store', $this->ogrenci), [
            'weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:00',
        ])->assertForbidden();
    }

    private function ders(User $ogretmen, User $ogrenci): PrivateLessonSlot
    {
        return PrivateLessonSlot::create([
            'student_id' => $ogrenci->id, 'teacher_id' => $ogretmen->id, 'branch' => $ogretmen->coach_subject,
            'weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:00', 'starts_on' => '2026-09-01',
        ]);
    }

    public function test_the_coach_sees_only_their_own_lessons_without_money(): void
    {
        $this->ders($this->ibrahim, $this->ogrenci);
        $this->ders($this->cahit, $this->oglu);
        Package::factory()->create(['name' => 'Gizli Paket', 'monthly_price' => 9999, 'includes_private_lessons' => true]);

        $this->actingAs($this->ibrahim)->get(route('coach.lessons.index'))
            ->assertOk()
            ->assertSee('Zeynep Kaya')
            ->assertSee('Her Perşembe 17:00–18:00')
            ->assertDontSee('Ali Acar')
            ->assertDontSee('₺')
            ->assertDontSee('Gizli Paket');
    }

    public function test_the_coach_cancels_and_moves_their_own_lesson(): void
    {
        $saat = $this->ders($this->ibrahim, $this->ogrenci);

        $this->actingAs($this->ibrahim)->post(route('coach.lessons.cancel', $saat), ['date' => '2026-10-01'])
            ->assertSessionHas('success', 'Ders iptal edildi.');
        $this->actingAs($this->ibrahim)->post(route('coach.lessons.move', $saat), [
            'date' => '2026-10-08', 'new_date' => '2026-10-09', 'new_starts_at' => '18:00', 'new_ends_at' => '19:00',
        ])->assertSessionHas('success', 'Ders taşındı.');

        $this->assertTrue($saat->exceptions()->whereDate('date', '2026-10-01')->sole()->cancelled);
        $this->assertSame('2026-10-09', $saat->exceptions()->whereDate('date', '2026-10-08')->sole()->new_date->toDateString());

        // Ders olmayan gune istisna yazilmaz.
        $this->actingAs($this->ibrahim)->post(route('coach.lessons.cancel', $saat), ['date' => '2026-10-02'])
            ->assertSessionHasErrors('date');
    }

    public function test_a_coach_cannot_touch_someone_elses_lesson(): void
    {
        $saat = $this->ders($this->cahit, $this->ogrenci);

        $this->actingAs($this->ibrahim)->post(route('coach.lessons.cancel', $saat), ['date' => '2026-10-01'])->assertForbidden();
        $this->actingAs($this->ibrahim)->patch(route('coach.lessons.reschedule', $saat), [
            'weekday' => 5, 'starts_at' => '16:00', 'ends_at' => '17:00',
        ])->assertForbidden();

        $this->assertSame(0, $saat->exceptions()->count());
    }

    /** Haftalik saat degisince gecmis dersler eski saatte kalir. */
    public function test_rescheduling_keeps_past_lessons_on_the_old_time(): void
    {
        $saat = $this->ders($this->ibrahim, $this->ogrenci);

        $this->actingAs($this->ibrahim)->patch(route('coach.lessons.reschedule', $saat), [
            'weekday' => 5, 'starts_at' => '16:00', 'ends_at' => '17:00',
        ])->assertSessionHas('success');

        $this->assertSame('2026-09-29', $saat->fresh()->ends_on->toDateString());
        $yeni = PrivateLessonSlot::whereKeyNot($saat->id)->sole();
        $this->assertSame([5, '16:00', $this->ibrahim->id, 'Matematik'], [$yeni->weekday, $yeni->starts_at, $yeni->teacher_id, $yeni->branch]);

        $dersler = \App\Support\PrivateLessonCalendar::between($this->ogrenci, '2026-09-21', '2026-10-04');
        $this->assertSame(['2026-09-24', '2026-10-02'], array_column($dersler, 'date'));
    }

    public function test_the_student_and_parent_see_the_teacher_and_subject(): void
    {
        $this->ders($this->ibrahim, $this->oglu);
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($this->oglu->id);

        // Tier 1 (paketinde ozel ders yok) ogrenci de dersini gorur.
        $this->actingAs($this->oglu)->get(route('user.plan', ['hafta' => '2026-09-28']))
            ->assertOk()
            ->assertSee('Özel ders · Matematik')
            ->assertSee('İbrahim Acar');

        $this->actingAs($veli)->get(route('parent.student', $this->oglu))
            ->assertOk()
            ->assertSee('Matematik · İbrahim Acar');
    }
}
