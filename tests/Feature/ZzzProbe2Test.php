<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZzzProbe2Test extends TestCase
{
    use RefreshDatabase;

    private function sinirlar(Carbon $now): array
    {
        $tz = config('kafe.timezone');
        [$s, $d] = array_map('intval', explode(':', config('kafe.kapanis')));
        $yerelNow = $now->copy()->setTimezone($tz);
        $L = $yerelNow->copy()->setTime($s, $d, 0);
        if ($L->greaterThan($yerelNow)) { $L->subDay(); }
        return [$L->copy()->utc(), $now->copy()->subHours((int) config('kafe.azami_saat'))];
    }

    /** SINIRA YAKIN dagilim: 0-13 saat geriye, saniye hassasiyetinde. */
    public function test_boundary_focused_equivalence(): void
    {
        $closer = app(SessionCloser::class);
        $uyusmazlik = 0; $stale = 0; $n = 6000;
        for ($i = 0; $i < $n; $i++) {
            $now = Carbon::parse('2026-01-01 00:00:00', 'UTC')->addSeconds(random_int(0, 400 * 86400));
            $started = $now->copy()->subSeconds(random_int(0, 13 * 3600));
            $s = new StudySession(); $s->started_at = $started->copy()->utc();
            [$L, $limit] = $this->sinirlar($now);
            $sql = $started->lessThan($L) || $started->lessThanOrEqualTo($limit);
            $php = $closer->isStale($s, $now);
            if ($sql !== $php) { $uyusmazlik++; if ($uyusmazlik < 4) fwrite(STDERR, "UYUSMAZLIK started={$started} now={$now}\n"); }
            if ($php) $stale++;
        }
        fwrite(STDERR, "SINIR ODAKLI: n={$n} stale={$stale} (%".round(100*$stale/$n)."), uyusmazlik={$uyusmazlik}\n");
        $this->assertSame(0, $uyusmazlik);
    }

    /** Tam sinir anlari: L, L-1sn, L+1sn, limit, limit+-1sn. */
    public function test_exact_boundaries(): void
    {
        $closer = app(SessionCloser::class);
        $now = Carbon::parse('2026-06-15 14:00:00', config('kafe.timezone'))->utc();
        [$L, $limit] = $this->sinirlar($now);
        foreach ([[$L, -1], [$L, 0], [$L, 1], [$limit, -1], [$limit, 0], [$limit, 1]] as [$ref, $off]) {
            $started = $ref->copy()->addSeconds($off);
            $s = new StudySession(); $s->started_at = $started->copy()->utc();
            $sql = $started->lessThan($L) || $started->lessThanOrEqualTo($limit);
            $php = $closer->isStale($s, $now);
            fwrite(STDERR, sprintf("started=%s sql=%s php=%s %s\n", $started, var_export($sql, true), var_export($php, true), $sql === $php ? 'OK' : 'FARK'));
            $this->assertSame($php, $sql);
        }
    }

    /** Gercek SQL: onerilen sorgu SQLite uzerinde ayni kumeyi mi donduruyor? */
    public function test_real_sql_matches_php_filter(): void
    {
        $closer = app(SessionCloser::class);
        $masa = StudyTable::create(['name' => 'M']);
        $now = Carbon::parse('2026-06-15 14:00:00', config('kafe.timezone'))->utc();
        [$L, $limit] = $this->sinirlar($now);

        $beklenen = [];
        for ($i = 0; $i < 60; $i++) {
            $ogr = User::factory()->create(['role' => Role::Student->value, 'subscription_status' => 'active']);
            $started = $now->copy()->subSeconds(random_int(0, 40 * 3600));
            $o = StudySession::create(['student_id' => $ogr->id, 'study_table_id' => $masa->id, 'started_at' => $started->copy()->utc()]);
            if ($closer->isStale($o->fresh(), $now)) { $beklenen[] = $o->id; }
        }
        sort($beklenen);

        $sorgular = [];
        DB::listen(function ($q) use (&$sorgular) { $sorgular[] = [$q->sql, $q->bindings]; });
        $bulunan = StudySession::open()
            ->where(fn ($q) => $q->where('started_at', '<', $L)->orWhere('started_at', '<=', $limit))
            ->pluck('id')->sort()->values()->all();
        fwrite(STDERR, "SQL: {$sorgular[0][0]} || baglar: " . json_encode(array_map('strval', $sorgular[0][1])) . "\n");
        fwrite(STDERR, "beklenen=" . count($beklenen) . " bulunan=" . count($bulunan) . "\n");
        $this->assertSame($beklenen, $bulunan);
    }

    /** Onerilen duzeltmeden SONRA istek basina sorgu sayisi degisiyor mu? */
    public function test_fix_does_not_reduce_query_count(): void
    {
        $now = now();
        [$L, $limit] = $this->sinirlar($now);
        $sorgular = [];
        DB::listen(function ($q) use (&$sorgular) { $sorgular[] = $q->sql; });
        StudySession::open()->where(fn ($q) => $q->where('started_at', '<', $L)->orWhere('started_at', '<=', $limit))->get();
        fwrite(STDERR, "DUZELTILMIS closeStale bos tabloda sorgu sayisi=" . count($sorgular) . "\n");
        $this->assertSame(1, count($sorgular));
    }
}
