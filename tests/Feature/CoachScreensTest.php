<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UX turu (23 Eyl) - koc ekranlari. Ogrencinin dort sayfasi (plan, notlar,
 * rapor, konular) ust bardaki dugmeler yerine SEKMELERLE baglanir; sekme
 * tek gecis yolu, kaybolursa sayfalar birbirinden kopar.
 */
class CoachScreensTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User} */
    private function kocVeOgrenci(): array
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        return [$koc, $ogrenci];
    }

    public function test_each_student_page_links_the_other_three_as_tabs(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();
        $rotalar = ['coach.plan.show', 'coach.notes.index', 'coach.report', 'coach.topics.index'];

        foreach ($rotalar as $sayfa) {
            $yanit = $this->actingAs($koc)->get(route($sayfa, $ogrenci))->assertOk();

            $yanit->assertSee('class="page-tab active"', false);
            foreach ($rotalar as $hedef) {
                $yanit->assertSee(route($hedef, $ogrenci), false);
            }
        }
    }

    /** Ekleme formu katli durur; hata varsa acik gelir ki koc hatayi gorsun. */
    public function test_the_add_form_is_open_after_a_validation_error(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        $this->actingAs($koc)->get(route('coach.plan.show', $ogrenci))
            ->assertDontSee('id="planaEkle" open', false);

        $this->actingAs($koc)
            ->from(route('coach.plan.show', $ogrenci))
            ->followingRedirects()
            ->post(route('coach.plan.store', $ogrenci), ['plan_date' => ''])
            ->assertSee('id="planaEkle" open', false);
    }
}
