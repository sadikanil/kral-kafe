<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\SessionEndReason;
use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudyStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Istatistik sorgu sayisi seriyle BUYUMEZ (QA perf P3).
 *
 * streak() her gun icin minutesOnDay() cagiriyordu: 60 gunluk seri 60 sorgu
 * demekti; ogrenci paneli 26'dan 86 sorguya cikiyordu. Fonksiyon ile
 * veritabani arasindaki her gidis-donus burada pahali (README SS10.12).
 */
class StudyStatsQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    /** Dunden geriye $gun gun, her gun 10:00-12:00 onayli oturum. */
    private function seriliOgrenci(int $gun): User
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
        $masa = StudyTable::create(['name' => 'Masa ' . uniqid()]);

        for ($g = 1; $g <= $gun; $g++) {
            $bas = Carbon::parse('2026-09-29 10:00', config('kafe.timezone'))->subDays($g)->utc();
            StudySession::create([
                'student_id' => $ogrenci->id, 'study_table_id' => $masa->id,
                'started_at' => $bas, 'ended_at' => $bas->copy()->addHours(2), 'duration_minutes' => 120,
                'end_reason' => SessionEndReason::Manual->value, 'approval_status' => ApprovalStatus::Approved->value,
            ]);
        }

        return $ogrenci;
    }

    /** @return array{0:mixed,1:int} sonuc ve sorgu sayisi */
    private function say(callable $is): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $sonuc = $is();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$sonuc, $n];
    }

    /**
     * Tek pencere okumasi: oturumlar + molalari (eager load) = en fazla 2
     * sorgu, seri ne kadar uzun olursa olsun.
     */
    public function test_the_streak_costs_the_same_queries_however_long_it_is(): void
    {
        $sorgular = [];
        foreach ([10, 45] as $gun) {
            $ogrenci = $this->seriliOgrenci($gun);

            [$seri, $sorgular[$gun]] = $this->say(fn () => app(StudyStats::class)->streak($ogrenci));

            $this->assertSame($gun, $seri);
        }

        $this->assertSame($sorgular[10], $sorgular[45]);
        $this->assertLessThanOrEqual(2, $sorgular[45]);
    }

    /** Pencereyi asan seri de dogru sayilir; yalnizca bir pencere daha okunur. */
    public function test_a_streak_longer_than_the_window_is_still_counted(): void
    {
        $ogrenci = $this->seriliOgrenci(70);

        [$seri, $sorgu] = $this->say(fn () => app(StudyStats::class)->streak($ogrenci));

        $this->assertSame(70, $seri);
        $this->assertLessThanOrEqual(4, $sorgu);
    }

    public function test_the_summary_is_one_window_read_and_matches_the_single_methods(): void
    {
        $ogrenci = $this->seriliOgrenci(40);
        $ist = app(StudyStats::class);

        [$ozet, $sorgu] = $this->say(fn () => $ist->summary($ogrenci));

        $this->assertLessThanOrEqual(2, $sorgu);
        $this->assertSame([
            'today' => $ist->todayMinutes($ogrenci),
            'week' => $ist->weekMinutes($ogrenci),
            'month' => $ist->monthMinutes($ogrenci),
            'streak' => $ist->streak($ogrenci),
        ], $ozet);
        $this->assertSame(['today' => 0, 'week' => 120, 'month' => 28 * 120, 'streak' => 40], $ozet);
    }

    /** Panelin sorgu sayisi seriden bagimsiz; calisma oturumu sorgulari sabit. */
    public function test_the_student_panel_query_count_does_not_grow_with_the_streak(): void
    {
        $olc = function (User $ogrenci): array {
            $this->app['auth']->forgetGuards();
            [, $n] = $this->say(fn () => $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk());

            return [$n];
        };

        [$kisa] = $olc($this->seriliOgrenci(10));
        [$uzun] = $olc($this->seriliOgrenci(45));

        $this->assertSame($kisa, $uzun);
        $this->assertLessThanOrEqual(25, $uzun);
    }
}
