<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\Notification;
use App\Models\Package;
use App\Models\User;
use App\Services\NotificationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 30a - Serbest deneme: tarihi ogrenci secer (ay icinde).
 *
 * exam_date BOS DEGIL: pencerenin ilk gunu. Sonuc siralamasi, net grafigi
 * ve raporlar exam_date'e dayaniyor; bos birakmak hepsini kirardi. Takvim
 * gunune, geri sayima ve "yarin deneme var" bildirimine GIRMEZ.
 */
class FlexibleExamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 12:00', config('kafe.timezone')));
    }

    private function serbest(string $bas = '2026-11-01', string $son = '2026-11-30'): ExamEvent
    {
        return ExamEvent::create([
            'title' => 'Hız ve Renk', 'exam_type' => 'tyt', 'exam_date' => $bas,
            'is_flexible' => true, 'available_until' => $son, 'note' => 'Deneme Kulübü',
        ]);
    }

    private function sabit(string $tarih = '2026-11-06'): ExamEvent
    {
        return ExamEvent::create(['title' => 'Apotemi', 'exam_type' => 'tyt', 'exam_date' => $tarih]);
    }

    public function test_a_flexible_exam_is_not_an_upcoming_dated_exam(): void
    {
        $this->serbest();
        $sabit = $this->sabit();

        $this->assertSame([$sabit->id], ExamEvent::upcoming()->pluck('id')->all());
    }

    public function test_a_flexible_exam_does_not_land_on_a_calendar_day(): void
    {
        $this->serbest();
        $sabit = $this->sabit();

        $this->assertSame([$sabit->id], ExamEvent::inMonth(2026, 11)->pluck('id')->all());
    }

    public function test_open_flexible_exams_are_those_whose_window_has_not_closed(): void
    {
        $kasim = $this->serbest();
        $this->serbest('2026-10-01', '2026-10-30');
        $this->sabit();

        $this->assertSame([$kasim->id], ExamEvent::flexibleOpen()->pluck('id')->all());
    }

    public function test_the_window_label(): void
    {
        $this->assertSame('1–30 Kasım', $this->serbest()->windowLabel());
    }

    public function test_no_tomorrow_reminder_for_a_flexible_exam(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->serbest('2026-11-01');

        app(NotificationBuilder::class)->examReminders('2026-10-31');

        $this->assertSame(0, Notification::where('user_id', $ogrenci->id)->count());
    }

    public function test_the_exam_calendar_lists_the_flexible_exams(): void
    {
        $this->serbest();
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create();

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Serbest denemeler')
            ->assertSee('Hız ve Renk')
            ->assertSee('1–30 Kasım');
    }

    public function test_the_admin_creates_a_flexible_exam(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.exams.store'), [
            'title' => 'Hız ve Renk', 'exam_type' => 'ayt', 'exam_date' => '2026-12-01',
            'is_flexible' => 1, 'available_until' => '2026-12-31',
        ])->assertRedirect();

        $deneme = ExamEvent::sole();
        $this->assertTrue($deneme->is_flexible);
        $this->assertSame('2026-12-31', $deneme->available_until->toDateString());
    }

    public function test_a_flexible_exam_needs_a_last_day_after_the_first(): void
    {
        $this->actingAs(User::factory()->admin()->create())->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => 'Hız ve Renk', 'exam_type' => 'ayt', 'exam_date' => '2026-12-01', 'is_flexible' => 1,
            ])
            ->assertSessionHasErrors('available_until');
    }
}
