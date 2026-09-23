<?php

namespace Tests\Feature;

use App\Enums\Grade;
use App\Enums\Role;
use App\Enums\StudyField;
use App\Models\ExamEvent;
use App\Models\Package;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 30b - Sinif, alan ve mufredattan gelen dersler.
 *
 * Ders listesi MEB 2025 haftalik ders cizelgesi ve 2026 YKS kilavuzundan
 * (migration 2026_09_23_170000). Ogrenci yalnizca sorumlu oldugu dersleri
 * gorur; sinif ya da alan bilinmiyorsa suzgec uygulanmaz - hicbir ders
 * sessizce kaybolmamali.
 */
class CurriculumTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(?Grade $sinif, ?StudyField $alan = null): User
    {
        return User::factory()->student()->create(['grade' => $sinif?->value, 'field' => $alan?->value]);
    }

    /** @return list<string> */
    private function kodlar(User $ogrenci): array
    {
        return Subject::forStudent($ogrenci)->pluck('code')->filter()->values()->all();
    }

    // --- Mufredat ------------------------------------------------------------

    public function test_the_curriculum_is_seeded(): void
    {
        foreach (['tyt_turkce', 'geometri', 'ayt_matematik', 'tarih_2', 'felsefe_grubu', 'okul_inkilap'] as $kod) {
            $this->assertTrue(Subject::where('code', $kod)->exists(), "{$kod} eksik");
        }
    }

    public function test_the_yks_topics_are_seeded_in_teaching_order(): void
    {
        $turkce = Subject::where('code', 'tyt_turkce')->sole();

        $this->assertSame('Sözcükte Anlam', $turkce->topics()->first()->name);
        $this->assertGreaterThan(400, \App\Models\SubjectTopic::count());
    }

    // --- Suzgec --------------------------------------------------------------

    public function test_a_tenth_grader_sees_tyt_and_school_courses(): void
    {
        $kodlar = $this->kodlar($this->ogrenci(Grade::Ten));

        $this->assertContains('tyt_turkce', $kodlar);
        $this->assertContains('okul_matematik', $kodlar);
        $this->assertContains('okul_felsefe', $kodlar);
        $this->assertNotContains('ayt_matematik', $kodlar);
        $this->assertNotContains('okul_inkilap', $kodlar);
    }

    public function test_a_numerical_twelfth_grader_sees_their_ayt_subjects(): void
    {
        $kodlar = $this->kodlar($this->ogrenci(Grade::Twelve, StudyField::Numerical));

        $this->assertContains('ayt_fizik', $kodlar);
        $this->assertContains('ayt_matematik', $kodlar);
        $this->assertContains('tyt_tarih', $kodlar, 'TYT herkese');
        $this->assertContains('okul_inkilap', $kodlar);
        $this->assertNotContains('edebiyat', $kodlar);
        $this->assertNotContains('tarih_2', $kodlar);
        $this->assertNotContains('okul_matematik', $kodlar);
    }

    public function test_an_equal_weight_graduate_sees_math_and_literature(): void
    {
        $kodlar = $this->kodlar($this->ogrenci(Grade::Graduate, StudyField::EqualWeight));

        $this->assertContains('ayt_matematik', $kodlar);
        $this->assertContains('edebiyat', $kodlar);
        $this->assertContains('tarih_1', $kodlar);
        $this->assertNotContains('tarih_2', $kodlar);
        $this->assertNotContains('ayt_fizik', $kodlar);
        $this->assertNotContains('okul_edebiyat', $kodlar, 'Mezunun okul dersi yok');
    }

    public function test_a_verbal_student_sees_the_social_sciences(): void
    {
        $kodlar = $this->kodlar($this->ogrenci(Grade::Eleven, StudyField::Verbal));

        $this->assertContains('tarih_2', $kodlar);
        $this->assertContains('felsefe_grubu', $kodlar);
        $this->assertNotContains('ayt_matematik', $kodlar);
    }

    /** Sinif ya da alan bilinmiyorsa hicbir ders gizlenmez. */
    public function test_unknown_grade_or_field_hides_nothing(): void
    {
        $hepsi = Subject::active()->count();

        $this->assertSame($hepsi, Subject::forStudent($this->ogrenci(null))->count());
        $this->assertContains('ayt_fizik', $this->kodlar($this->ogrenci(Grade::Twelve)));
    }

    // --- Kullanici formu -----------------------------------------------------

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string,mixed> */
    private function yeniOgrenci(array $ek = []): array
    {
        return array_merge([
            'name' => 'Ayşe', 'phone' => '0532 111 22 33', 'role' => Role::Student->value,
            'package_id' => Package::factory()->tier1()->create()->id,
            'new_parent_name' => 'Anne', 'new_parent_phone' => '0532 999 88 77',
        ], $ek);
    }

    public function test_grade_and_field_are_set_when_creating_a_student(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), $this->yeniOgrenci(['grade' => '12', 'field' => 'say']))
            ->assertSessionHasNoErrors();

        $ogrenci = User::where('name', 'Ayşe')->sole();
        $this->assertSame(Grade::Twelve, $ogrenci->gradeEnum());
        $this->assertSame(StudyField::Numerical, $ogrenci->fieldEnum());
    }

    /** 9-10. sinifta alan yok: formdan gelse de kaydedilmez. */
    public function test_a_field_is_dropped_below_the_eleventh_grade(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), $this->yeniOgrenci(['grade' => '10', 'field' => 'say']));

        $this->assertNull(User::where('name', 'Ayşe')->sole()->field);
    }

    public function test_an_unknown_grade_is_refused(): void
    {
        $this->actingAs($this->yonetici())->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->yeniOgrenci(['grade' => '8']))
            ->assertSessionHasErrors('grade');
    }

    public function test_grade_and_field_are_updated(): void
    {
        $ogrenci = $this->ogrenci(Grade::Eleven, StudyField::Numerical);
        $veli = User::factory()->parent()->create();
        $ogrenci->parents()->attach($veli);

        $this->actingAs($this->yonetici())->put(route('admin.users.update', $ogrenci), [
            'name' => $ogrenci->name, 'phone' => '0532 444 55 66', 'role' => Role::Student->value,
            'subscription_status' => 'active', 'grade' => 'mezun', 'field' => 'ea',
        ])->assertSessionHasNoErrors();

        $this->assertSame('mezun', $ogrenci->fresh()->grade);
        $this->assertSame('ea', $ogrenci->fresh()->field);
    }

    public function test_the_forms_offer_grade_and_field(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.users.create'))
            ->assertOk()->assertSee('name="grade"', false)->assertSee('Eşit Ağırlık');

        $this->actingAs($this->yonetici())->get(route('admin.users.edit', $this->ogrenci(Grade::Nine)))
            ->assertOk()->assertSee('name="field"', false);
    }

    // --- Ekranlarda suzgec -----------------------------------------------------

    public function test_the_coach_plan_offers_only_the_students_subjects(): void
    {
        $ogrenci = $this->ogrenci(Grade::Twelve, StudyField::Numerical);

        $this->actingAs($this->yonetici())->get(route('coach.plan.show', $ogrenci))
            ->assertOk()
            ->assertSee('AYT Fizik')
            ->assertDontSee('Tarih-2');
    }

    public function test_the_exam_result_form_shows_the_events_subjects_for_the_field(): void
    {
        $ogrenci = $this->ogrenci(Grade::Twelve, StudyField::Numerical);
        $ayt = ExamEvent::create(['title' => 'Ulti', 'exam_type' => 'ayt', 'exam_date' => '2026-11-28']);

        $this->actingAs($this->yonetici())->get(route('admin.exam-results.edit', [$ayt, $ogrenci]))
            ->assertOk()
            ->assertSee('AYT Fizik')
            ->assertDontSee('TYT Türkçe')
            ->assertDontSee('Edebiyat');
    }
}
