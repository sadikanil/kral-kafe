<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Serbest deneme ogrencinin planinda TEK gun (QA hata 8).
 *
 * "Ekle"ye iki kez dokunmak ya da mobilde POST'un yeniden gonderilmesi ayni
 * denemeyi takvime iki kez koyuyordu; haftalik ilerleme 0/2 okunuyordu.
 * Ikinci istek artik yeni satir acmaz, var olani o gune tasir.
 */
class FlexibleExamPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
    }

    private function serbest(string $ad = 'Özdebir Türkiye Geneli'): ExamEvent
    {
        return ExamEvent::create([
            'title' => $ad, 'exam_type' => 'tyt', 'exam_date' => '2026-10-01',
            'is_flexible' => true, 'available_until' => '2026-10-31',
        ]);
    }

    public function test_picking_another_day_moves_the_exam_instead_of_adding_it_again(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbest();

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10']);
        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-20'])
            ->assertRedirect(route('user.plan', ['hafta' => '2026-10-20']))
            ->assertSessionHas('success', 'Deneme 20 Ekim gününe taşındı.');

        $madde = StudyPlanItem::sole();
        $this->assertSame('2026-10-20', $madde->plan_date->toDateString());
        $this->assertSame('2026-10-19', $madde->week_start->toDateString());
    }

    /** Baskasinin (kocun) maddesine dokunulmaz; ogrenci yalnizca kendi koydugunu tasir. */
    public function test_a_coach_item_for_the_same_exam_is_left_alone(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->serbest();
        $kocunki = StudyPlanItem::create([
            'student_id' => $ogrenci->id, 'exam_event_id' => $deneme->id, 'title' => 'Koçun koyduğu',
            'plan_date' => '2026-10-11', 'week_start' => '2026-10-05', 'period' => 'week',
            'created_by' => User::factory()->create(['role' => 'coach'])->id,
        ]);

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10']);

        $this->assertSame('2026-10-11', $kocunki->fresh()->plan_date->toDateString());
        $this->assertSame(1, StudyPlanItem::where('created_by', $ogrenci->id)->count());
    }

    public function test_the_form_shows_the_chosen_day_and_offers_to_move_it(): void
    {
        $ogrenci = $this->ogrenci();
        $planda = $this->serbest('Planlı Deneme');
        $this->serbest('Boşta Deneme');

        $this->actingAs($ogrenci)->post(route('user.plan.exam'), ['exam_event_id' => $planda->id, 'plan_date' => '2026-10-10']);

        $this->actingAs($ogrenci)->get(route('user.plan'))
            ->assertOk()
            ->assertSeeInOrder(['Planlı Deneme', 'Planında: 10 Ekim', 'value="2026-10-10"', 'Taşı', 'Boşta Deneme', 'Ekle'], false);
    }

    /**
     * Es zamanli cift dokunus: iki POST ayni anda gelirse ikisi de "yok"
     * gorur. Dugme ilk gonderimde kilitlenir; ikinci istek hic cikmaz.
     */
    public function test_the_add_button_locks_on_the_first_submit(): void
    {
        $this->serbest();

        $this->actingAs($this->ogrenci())->get(route('user.plan'))
            ->assertOk()
            ->assertSee('onsubmit="this.querySelector(\'button\').disabled = true"', false);
    }
}
