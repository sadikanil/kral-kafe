<?php
namespace Tests\Feature;
use App\Models\StudySession; use App\Services\SessionCloser;
use Illuminate\Support\Carbon; use Tests\TestCase;
class ZzzProbe3Test extends TestCase
{
    /** now = kapanistan 30 dk sonra -> limit sinirindan UZAK, L sinirini yalitir. */
    public function test_L_boundary_isolated(): void
    {
        $closer = app(SessionCloser::class);
        $tz = config('kafe.timezone');
        $now = Carbon::parse('2026-06-15 21:30:00', $tz)->utc();
        $L = Carbon::parse('2026-06-15 21:00:00', $tz)->utc();
        $limit = $now->copy()->subHours(12);
        foreach ([-2, -1, 0, 1, 2] as $off) {
            $started = $L->copy()->addSeconds($off);
            $s = new StudySession(); $s->started_at = $started->copy()->utc();
            $sql = $started->lessThan($L) || $started->lessThanOrEqualTo($limit);
            $php = $closer->isStale($s, $now);
            fwrite(STDERR, sprintf("L%+d started=%s sql=%-5s php=%-5s %s\n", $off, $started,
                var_export($sql, true), var_export($php, true), $sql === $php ? 'OK' : 'FARK'));
            $this->assertSame($php, $sql);
        }
    }
}
