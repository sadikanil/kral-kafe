<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 5 - Haftalik calisma hedefi (MVP #5) ve panel gosterimi (MVP #4, #6).
 *
 * Hedefin gecerlilik araligi var. Sebep: koc hedefi yukseltince GECMIS
 * haftalarin "tuttu mu" cevabi degismemeli. Tek bir sutun (users.hedef) bunu
 * yapamaz - hedefi degistiren kisi farkinda olmadan gecmisi yeniden yazar.
 */
class StudyGoalTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function yonetici(): User
    {
        return User::factory()->create([
            'role' => Role::Admin->value,
            'subscription_status' => 'active',
        ]);
    }

    private function oturum(User $ogrenci, string $bas, string $bit): void
    {
        $b = Carbon::parse($bas, config('kafe.timezone'));
        $s = Carbon::parse($bit, config('kafe.timezone'));

        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $b->copy()->utc(),
            'ended_at' => $s->copy()->utc(),
            'duration_minutes' => (int) $b->diffInMinutes($s),
            'end_reason' => SessionEndReason::Manual->value,
        ]);
    }

    public function test_a_student_without_a_goal_has_none(): void
    {
        $this->assertNull(StudyGoal::activeFor($this->ogrenci(), '2026-09-16'));
    }

    public function test_the_goal_in_effect_on_a_date_is_the_one_returned(): void
    {
        $ogrenci = $this->ogrenci();

        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
            'effective_to' => '2026-09-14',
        ]);
        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 1200,
            'effective_from' => '2026-09-15',
        ]);

        $this->assertSame(600, StudyGoal::activeFor($ogrenci, '2026-09-10')?->target_minutes);
        $this->assertSame(1200, StudyGoal::activeFor($ogrenci, '2026-09-20')?->target_minutes);
    }

    /** Hedefi yukseltmek gecmis haftanin cevabini degistirmemeli. */
    public function test_raising_the_goal_does_not_rewrite_history(): void
    {
        $ogrenci = $this->ogrenci();

        $eski = StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);

        // Koc hedefi yukseltiyor - eski kayit kapatilir, yenisi acilir.
        $eski->supersedeOn('2026-09-15');
        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 1500,
            'effective_from' => '2026-09-15',
        ]);

        $this->assertSame(600, StudyGoal::activeFor($ogrenci, '2026-09-10')?->target_minutes);
        $this->assertSame(1500, StudyGoal::activeFor($ogrenci, '2026-09-15')?->target_minutes);
    }

    public function test_an_admin_can_set_a_weekly_goal_from_the_user_form(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici())
            ->put(route('admin.users.update', $ogrenci), [
                'name' => $ogrenci->name,
                'email' => $ogrenci->email,
                'role' => Role::Student->value,
                'subscription_status' => 'active',
                'weekly_goal_hours' => 20,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1200, StudyGoal::activeFor($ogrenci->fresh(), now()->toDateString())?->target_minutes);
    }

    public function test_changing_the_goal_closes_the_previous_one_instead_of_editing_it(): void
    {
        $ogrenci = $this->ogrenci();
        $yonetici = $this->yonetici();

        foreach ([20, 25] as $saat) {
            $this->actingAs($yonetici)->put(route('admin.users.update', $ogrenci), [
                'name' => $ogrenci->name,
                'email' => $ogrenci->email,
                'role' => Role::Student->value,
                'subscription_status' => 'active',
                'weekly_goal_hours' => $saat,
            ]);
        }

        $this->assertSame(2, StudyGoal::where('student_id', $ogrenci->id)->count(),
            'Hedef guncellenirken uzerine yazilmis; gecmis kaybolur');
        $this->assertSame(1500, StudyGoal::activeFor($ogrenci, now()->toDateString())?->target_minutes);
    }

    public function test_the_dashboard_shows_time_streak_and_goal_progress(): void
    {
        $ogrenci = $this->ogrenci();

        StudyGoal::create([
            'student_id' => $ogrenci->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);

        // 14 Eylul pazartesi, 15 Eylul sali
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 13:00');
        $this->oturum($ogrenci, '2026-09-15 10:00', '2026-09-15 12:00');

        Carbon::setTestNow(Carbon::parse('2026-09-15 20:00', config('kafe.timezone')));

        $this->actingAs($ogrenci)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('2s 0dk')        // bugun
            ->assertSee('5s 0dk')        // bu hafta
            ->assertSee('10s 0dk')       // haftalik hedef
            ->assertSee('progress-bar', false)
            ->assertSee('2 gün');        // devamlilik serisi

        Carbon::setTestNow();
    }

    public function test_a_student_without_a_goal_still_sees_the_dashboard(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)
            ->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('progress-bar', false);
    }
}
