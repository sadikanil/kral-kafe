<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Support\WeekPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Haftanin iki ucu (QA hata 9/20/25).
 *
 * 'date' cast'i SQLite'ta "2026-10-04 00:00:00" yaziyor; kapali bir
 * whereBetween(..., '2026-10-04') metin karsilastirmasinda Pazar'i disarida
 * birakiyordu. Pencere yari acik olmali: [pazartesi, sonraki pazartesi).
 * Hafta: 28 Eylul (Pzt) - 4 Ekim (Paz).
 */
class WeekPlanBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create(['role' => Role::Student->value, 'subscription_status' => 'active']);
    }

    private function madde(User $ogrenci, string $gun, string $baslik): void
    {
        StudyPlanItem::create([
            'student_id' => $ogrenci->id, 'title' => $baslik, 'plan_date' => $gun,
            'week_start' => '2026-09-28', 'period' => 'week',
        ]);
    }

    /** @return array<string,list<string>> tarih => basliklar */
    private function gunler(User $ogrenci, string $alan): array
    {
        return collect(WeekPlan::for($ogrenci, '2026-09-30'))
            ->mapWithKeys(fn (array $g) => [$g['date'] => $g[$alan]->pluck('title')->all()])
            ->filter()
            ->all();
    }

    public function test_items_on_monday_and_sunday_are_in_the_week_and_neighbours_are_not(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, '2026-09-27', 'Onceki pazar');
        $this->madde($ogrenci, '2026-09-28', 'Pazartesi');
        $this->madde($ogrenci, '2026-10-04', 'Pazar');
        $this->madde($ogrenci, '2026-10-05', 'Sonraki pazartesi');

        $this->assertSame(
            ['2026-09-28' => ['Pazartesi'], '2026-10-04' => ['Pazar']],
            $this->gunler($ogrenci, 'items'),
        );
    }

    public function test_a_dated_exam_on_sunday_is_in_the_week_and_neighbours_are_not(): void
    {
        foreach (['2026-09-27' => 'Onceki pazar denemesi', '2026-09-28' => 'Pazartesi denemesi', '2026-10-04' => 'Pazar denemesi', '2026-10-05' => 'Sonraki pazartesi denemesi'] as $gun => $ad) {
            ExamEvent::create(['title' => $ad, 'exam_type' => 'tyt', 'exam_date' => $gun]);
        }

        $this->assertSame(
            ['2026-09-28' => ['Pazartesi denemesi'], '2026-10-04' => ['Pazar denemesi']],
            $this->gunler($this->ogrenci(), 'exams'),
        );
    }
}
