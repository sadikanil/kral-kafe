<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ZzzProbeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'subscription_status' => 'active']);
    }

    public function test_query_counts(): void
    {
        $admin = $this->admin();

        foreach (['admin.stock.index', 'admin.products.index', 'admin.reports.index', 'admin.users.index'] as $name) {
            if (! Route::has($name)) { fwrite(STDERR, "YOK: $name\n"); continue; }
            $url = route($name);
            $sorgular = [];
            DB::listen(function ($q) use (&$sorgular) { $sorgular[] = $q->sql; });
            $r = $this->actingAs($admin)->get($url);
            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
            $oturum = array_values(array_filter($sorgular, fn ($s) => str_contains($s, 'study_sessions')));
            fwrite(STDERR, sprintf("%-22s status=%d toplam=%d study_sessions=%d :: %s\n",
                $name, $r->status(), count($sorgular), count($oturum), implode(' | ', $oturum)));
        }
        $this->assertTrue(true);
    }

    public function test_guest_request_runs_no_session_query(): void
    {
        $sorgular = [];
        DB::listen(function ($q) use (&$sorgular) { $sorgular[] = $q->sql; });
        $this->get('/giris');
        $oturum = array_filter($sorgular, fn ($s) => str_contains($s, 'study_sessions'));
        fwrite(STDERR, sprintf("GUEST /giris toplam=%d study_sessions=%d\n", count($sorgular), count($oturum)));
        $this->assertTrue(true);
    }

    /** Onerilen SQL yukleminin PHP isStale ile esdegerligi. */
    public function test_proposed_predicate_equivalence(): void
    {
        $closer = app(SessionCloser::class);
        $tz = config('kafe.timezone');
        [$saat, $dk] = array_map('intval', explode(':', config('kafe.kapanis')));
        $azami = (int) config('kafe.azami_saat');

        $uyusmazlik = 0;
        $stale = 0;
        for ($i = 0; $i < 4000; $i++) {
            $now = Carbon::parse('2026-01-01 00:00:00', 'UTC')->addSeconds(random_int(0, 400 * 86400));
            $started = $now->copy()->subSeconds(random_int(0, 40 * 86400));

            $s = new StudySession(['started_at' => $started->copy()->utc()]);
            $s->started_at = $started->copy()->utc();

            // L = now'dan onceki/esit en son yerel kapanis
            $yerelNow = $now->copy()->setTimezone($tz);
            $L = $yerelNow->copy()->setTime($saat, $dk, 0);
            if ($L->greaterThan($yerelNow)) { $L->subDay(); }
            $Lutc = $L->copy()->utc();

            $sql = $started->lessThan($Lutc) || $started->lessThanOrEqualTo($now->copy()->subHours($azami));
            $php = $closer->isStale($s, $now);

            if ($sql !== $php) { $uyusmazlik++; if ($uyusmazlik < 4) fwrite(STDERR, "UYUSMAZLIK started={$started} now={$now} L={$Lutc} sql=" . var_export($sql, true) . " php=" . var_export($php, true) . "\n"); }
            if ($php) $stale++;
        }
        fwrite(STDERR, "ESDEGERLIK: 4000 cift, stale={$stale}, uyusmazlik={$uyusmazlik}\n");
        $this->assertSame(0, $uyusmazlik);
    }
}
