<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\PrivateLessonSlot;
use App\Models\User;
use App\Support\PrivateLessonCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 25: Tier 3 (ozel ders hakki olan) ogrencinin ozel ders saatleri.
 * Haftalik sabit saat + tek seferlik iptal/tasima (karar, 23 Eyl).
 * Yalnizca yonetici (Cahit Hoca) duzenler; ogrenci ve veli gorur.
 */
class PrivateLessonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 23 Eylul 2026 Carsamba
        Carbon::setTestNow(Carbon::parse('2026-09-23 09:00', config('kafe.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kral(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier3())->create();
    }

    private function saat(User $ogrenci, int $gun = 3, string $bas = '17:00', string $bit = '18:30', array $ek = []): PrivateLessonSlot
    {
        return PrivateLessonSlot::create(array_merge([
            'student_id' => $ogrenci->id, 'weekday' => $gun,
            'starts_at' => $bas, 'ends_at' => $bit, 'starts_on' => '2026-09-01',
        ], $ek));
    }

    private function dersler(User $ogrenci, string $bas, string $bit): array
    {
        return PrivateLessonCalendar::between($ogrenci, $bas, $bit);
    }

    // --- Takvim hesabi ------------------------------------------------------

    public function test_a_weekly_slot_repeats_on_its_weekday(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci, 3); // Carsamba

        $tarihler = array_column($this->dersler($ogrenci, '2026-09-21', '2026-10-04'), 'date');

        $this->assertSame(['2026-09-23', '2026-09-30'], $tarihler);
    }

    public function test_a_slot_respects_its_start_and_end(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci, 3, ek: ['starts_on' => '2026-09-24', 'ends_on' => '2026-10-10']);

        $tarihler = array_column($this->dersler($ogrenci, '2026-09-01', '2026-10-31'), 'date');

        $this->assertSame(['2026-09-30', '2026-10-07'], $tarihler);
    }

    public function test_a_cancelled_lesson_stays_visible_as_cancelled(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci)->exceptions()->create(['date' => '2026-09-30', 'cancelled' => true]);

        $ders = collect($this->dersler($ogrenci, '2026-09-28', '2026-10-04'))->sole();

        $this->assertSame('cancelled', $ders['status']);
    }

    public function test_a_moved_lesson_appears_on_its_new_day_and_time(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci)->exceptions()->create([
            'date' => '2026-09-30', 'new_date' => '2026-10-01', 'new_starts_at' => '10:00', 'new_ends_at' => '11:00',
        ]);

        $ders = collect($this->dersler($ogrenci, '2026-09-28', '2026-10-04'))->sole();

        $this->assertSame(['2026-10-01', '10:00', '11:00', 'moved'], [$ders['date'], $ders['starts_at'], $ders['ends_at'], $ders['status']]);
    }

    public function test_lessons_come_in_order(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci, 5, '10:00', '11:00'); // Cuma
        $this->saat($ogrenci, 1, '15:00', '16:00'); // Pazartesi

        $tarihler = array_column($this->dersler($ogrenci, '2026-09-28', '2026-10-04'), 'date');

        $this->assertSame(['2026-09-28', '2026-10-02'], $tarihler);
    }

    // --- Yonetici -----------------------------------------------------------

    public function test_the_admin_adds_a_weekly_slot(): void
    {
        $ogrenci = $this->kral();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.store', $ogrenci), ['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:30'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PrivateLessonSlot::where('student_id', $ogrenci->id)->count());
    }

    public function test_the_end_must_be_after_the_start(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.store', $this->kral()), ['weekday' => 3, 'starts_at' => '18:00', 'ends_at' => '17:00'])
            ->assertSessionHasErrors('ends_at');
    }

    public function test_no_slot_without_private_lessons_in_the_package(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier2())->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.store', $ogrenci), ['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:00'])
            ->assertSessionHas('error');

        $this->assertSame(0, PrivateLessonSlot::count());
    }

    public function test_only_the_admin_edits_lessons(): void
    {
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->actingAs($koc)
            ->post(route('admin.lessons.store', $this->kral()), ['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:00'])
            ->assertForbidden();
    }

    public function test_the_admin_cancels_one_lesson(): void
    {
        $saat = $this->saat($this->kral());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($saat->exceptions()->sole()->cancelled);
    }

    public function test_the_admin_moves_one_lesson(): void
    {
        $saat = $this->saat($this->kral());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.move', $saat), [
                'date' => '2026-09-30', 'new_date' => '2026-10-01', 'new_starts_at' => '10:00', 'new_ends_at' => '11:00',
            ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-01', $saat->exceptions()->sole()->new_date->toDateString());
    }

    public function test_a_lesson_is_cancelled_only_on_its_own_weekday(): void
    {
        $saat = $this->saat($this->kral()); // Carsamba

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-10-01']) // Persembe
            ->assertSessionHasErrors('date');
    }

    public function test_the_admin_removes_a_slot(): void
    {
        $saat = $this->saat($this->kral());

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.lessons.destroy', $saat));

        $this->assertSame(0, PrivateLessonSlot::count());
    }

    public function test_the_edit_page_shows_the_lessons_section_for_tier_three(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.edit', $ogrenci))
            ->assertSee('Özel ders')
            ->assertSee('17:00');
    }

    // --- Ogrenci ve veli ----------------------------------------------------

    public function test_the_student_sees_lessons_in_the_calendar(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci);

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Özel ders')
            ->assertSee('17:00');
    }

    public function test_the_parent_sees_the_childs_next_lessons(): void
    {
        $ogrenci = $this->kral();
        $this->saat($ogrenci);
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($ogrenci);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertSee('Özel ders')
            ->assertSee('17:00');
    }
}
