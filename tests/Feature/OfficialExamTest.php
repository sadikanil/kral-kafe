<?php

namespace Tests\Feature;

use App\Enums\ExamType;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\StudentParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 15b - Sinava geri sayim.
 *
 * YKS/LGS bir DENEME DEGIL, hedefin kendisi. Ayri bir tablo ya da bayrak
 * gerekmiyor (SS7-G): ExamType::Official yetiyor, exam_events altyapisi
 * Dalga 6b'den hazir.
 *
 * Ayrimin bedeli var ve bilerek odendi: resmi sinav "siradaki deneme"
 * hatirlaticisina GIRMEZ. Girseydi kutu "Sıradaki deneme: YKS" derdi -
 * YKS'ye deneme demek, ogrencinin hafta sonu cozecegi denemeyle girecegi
 * sinavi ayni kefeye koymak olurdu.
 */
class OfficialExamTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->withPackage(\App\Models\Package::factory()->tier3())->create([
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

    private function sinav(string $baslik, string $gun, string $tur = 'official'): ExamEvent
    {
        return ExamEvent::create([
            'title' => $baslik,
            'exam_type' => $tur,
            'exam_date' => $gun,
        ]);
    }

    private function bugun(string $gun = '2026-09-22 10:00'): void
    {
        $this->travelTo(Carbon::parse($gun, config('kafe.timezone')));
    }

    // --- Tur -----------------------------------------------------------------

    public function test_the_official_type_exists(): void
    {
        $this->assertSame('official', ExamType::Official->value);
        $this->assertSame('Resmî Sınav', ExamType::Official->label());
    }

    // --- Geri sayim ----------------------------------------------------------

    public function test_a_student_sees_the_countdown_to_the_official_exam(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sinav('YKS 2027', '2027-06-19');
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('YKS 2027')
            ->assertSee('270 gün kaldı');
    }

    public function test_a_parent_sees_the_countdown_too(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);
        $this->sinav('YKS 2027', '2027-06-19');
        $this->bugun();

        $this->actingAs($veli)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('YKS 2027');
    }

    /** En YAKIN resmi sinav gosterilir, ilk girilen degil. */
    public function test_the_nearest_official_exam_wins(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sinav('YKS 2028', '2028-06-17');
        $this->sinav('YKS 2027', '2027-06-19');
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('YKS 2027')
            ->assertDontSee('YKS 2028');
    }

    public function test_a_past_official_exam_is_not_counted_down(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sinav('YKS 2026', '2026-06-20');
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('YKS 2026');
    }

    public function test_without_an_official_exam_nothing_is_drawn(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('Sınava kalan');
    }

    // --- Deneme hatirlaticisindan ayrilma ------------------------------------

    /**
     * Resmi sinav "siradaki deneme" kutusuna GIRMEZ.
     *
     * scopeUpcoming() bu ana kadar her turu donuyordu. Ayrim olmasa kutu
     * "Sıradaki deneme: YKS 2027" derdi.
     */
    public function test_the_official_exam_stays_out_of_the_practice_reminder(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sinav('YKS 2027', '2027-06-19');
        $this->sinav('TYT Deneme 5', '2026-09-26', 'tyt');
        $this->bugun();

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Sıradaki deneme')
            ->assertSee('TYT Deneme 5');

        // Hatirlaticinin kendisi yalnizca denemeleri tasir.
        $this->assertSame(
            ['TYT Deneme 5'],
            ExamEvent::upcoming()->pluck('title')->all(),
        );
    }

    /**
     * DERS turu olarak "Resmî Sınav" secilemez.
     *
     * subjects.exam_type bir dersin hangi sinavda cikacagini soyluyor
     * (TYT/AYT/LGS). "Resmî Sınav" orada anlamsiz bir secenek - yeni bir
     * enum degeri eklemenin sessiz yan etkisi.
     */
    public function test_the_subject_form_does_not_offer_the_official_type(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin->value]))
            ->get(route('admin.subjects.index'))
            ->assertOk()
            ->assertSee('TYT')
            ->assertDontSee('Resmî Sınav');
    }

    /** Resmi sinav takvimde DURUR - yalnizca hatirlaticidan cikti. */
    public function test_the_official_exam_still_appears_in_the_calendar(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sinav('YKS 2027', '2027-06-19');
        $this->bugun();

        $this->actingAs($ogrenci)
            ->get(route('user.exams', ['ay' => '2027-06']))
            ->assertOk()
            ->assertSee('YKS 2027');
    }
}
