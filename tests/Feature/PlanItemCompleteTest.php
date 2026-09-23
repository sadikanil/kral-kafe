<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "✓ Bitti" geri alinabilir ve sayfayi basa atmaz (QA a11y A15).
 *
 * Yanlis dokunus ogrencinin geri alamayacagi bir "tamamlandi"ydi; koc ve
 * veli maddeyi bitmis goruyordu. Her dokunustan sonra haftalik takvim en
 * uste donuyordu: persembe maddelerini isaretleyen her seferinde geri
 * kaydiriyordu. Bitmis madde yalnizca soluk ve ustu cizili - ekran okuyucu
 * "tamamlandi" duymuyordu.
 */
class PlanItemCompleteTest extends TestCase
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

    private function madde(User $ogrenci, array $ek = []): StudyPlanItem
    {
        return StudyPlanItem::create(array_merge([
            'student_id' => $ogrenci->id, 'title' => 'Türev tekrarı', 'plan_date' => '2026-10-01',
            'week_start' => '2026-09-28', 'period' => 'week',
        ], $ek));
    }

    public function test_completing_on_the_week_page_returns_to_the_item(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci);
        $plan = route('user.plan', ['hafta' => '2026-09-28']);

        $this->actingAs($ogrenci)->from($plan)
            ->post(route('user.study-plan.complete', $madde))
            ->assertRedirect($plan . '#madde-' . $madde->id);
    }

    public function test_the_student_can_reopen_their_own_item(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci);
        $madde->markDone();
        $plan = route('user.plan');

        $this->actingAs($ogrenci)->from($plan)
            ->post(route('user.study-plan.reopen', $madde))
            ->assertRedirect($plan . '#madde-' . $madde->id)
            ->assertSessionHas('success', 'Madde yeniden açıldı.');

        $madde->refresh();
        $this->assertSame('open', $madde->status);
        $this->assertNull($madde->completed_at);
    }

    public function test_another_students_item_cannot_be_reopened(): void
    {
        $madde = $this->madde($this->ogrenci());
        $madde->markDone();

        $this->actingAs($this->ogrenci())
            ->post(route('user.study-plan.reopen', $madde))
            ->assertForbidden();

        $this->assertSame('done', $madde->fresh()->status);
    }

    public function test_the_calendar_marks_done_items_and_offers_undo(): void
    {
        $ogrenci = $this->ogrenci();
        $acik = $this->madde($ogrenci, ['title' => 'Açık madde']);
        $bitmis = $this->madde($ogrenci, ['title' => 'Bitmiş madde']);
        $bitmis->markDone();

        $html = $this->actingAs($ogrenci)->get(route('user.plan', ['hafta' => '2026-09-28']))->assertOk()->getContent();

        $this->assertStringContainsString('id="madde-' . $acik->id . '"', $html);
        $this->assertStringContainsString('id="madde-' . $bitmis->id . '"', $html);
        $this->assertStringContainsString('<span role="img" aria-label="Tamamlandı">✓</span>', $html);
        $this->assertStringContainsString(route('user.study-plan.reopen', $bitmis), $html);
        $this->assertStringNotContainsString(route('user.study-plan.reopen', $acik), $html);
        // Dokunma hedefi: tam genislik ve en az 44 px.
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn btn-sm btn-success btn-block" style="min-height: 44px;">✓ Bitti<\/button>/', $html);
    }

    /** Kocun madde menusu (⋯) ekran okuyucuda adiyla okunur (QA a11y A8). */
    public function test_the_coach_item_menu_has_an_accessible_name(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, ['title' => 'Limit soruları']);
        $koc = User::factory()->create(['role' => 'coach']);
        $koc->coachStudents()->attach($ogrenci->id);

        $this->actingAs($koc)->get(route('coach.plan.show', [$ogrenci, 'hafta' => '2026-09-28']))
            ->assertOk()
            ->assertSee('<summary aria-label="Madde işlemleri: Limit soruları">⋯</summary>', false);
    }

    /** Veli ve koc bitmis maddeyi gorur ama geri alamaz. */
    public function test_the_parent_calendar_has_no_undo(): void
    {
        $ogrenci = $this->ogrenci();
        $madde = $this->madde($ogrenci);
        $madde->markDone();
        $veli = User::factory()->parent()->create();
        \App\Models\StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('<span role="img" aria-label="Tamamlandı">✓</span>', false)
            ->assertDontSee(route('user.study-plan.reopen', $madde), false);
    }
}
