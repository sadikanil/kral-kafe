<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\SessionCloser;
use App\Services\StudySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZzScratchRaceProbeTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function yerel(string $z): Carbon
    {
        return Carbon::parse($z, config('kafe.timezone'));
    }

    private function oturum(User $o, Carbon $b, ?StudyTable $m = null): StudySession
    {
        return StudySession::create([
            'student_id' => $o->id,
            'study_table_id' => ($m ?? StudyTable::create(['name' => 'Masa ' . uniqid()]))->id,
            'started_at' => $b->copy()->utc(),
        ]);
    }

    /** PROBE 1: over_limit -> switched ezilmesi */
    public function test_probe_overwrite(): void
    {
        $ogr = $this->ogrenci();
        // 09:00 baslayan -> limit 21:00, kapanis 21:00 -> auto_closed.
        // over_limit icin limit kapanistan ONCE dolmali: sabah 05:00 basla -> limit 17:00 < kapanis 21:00
        $s = $this->oturum($ogr, $this->yerel('2026-09-21 05:00'));

        $bayat = StudySession::find($s->id); // acik haliyle okundu (yaris: okuyan istek)

        Carbon::setTestNow($this->yerel('2026-09-21 23:00')->utc());
        app(SessionCloser::class)->closeStale();

        $sonra = StudySession::find($s->id);
        fwrite(STDERR, "\nKAPANIS SONRASI: reason={$sonra->end_reason->value} dakika={$sonra->duration_minutes} ended_at={$sonra->ended_at}\n");

        // bayat model hala ended_at=null tasiyor
        fwrite(STDERR, "BAYAT MODEL ended_at: " . var_export($bayat->ended_at, true) . "\n");

        app(StudySessionService::class)->close($bayat, SessionEndReason::Switched);

        $final = StudySession::find($s->id);
        fwrite(STDERR, "EZILDIKTEN SONRA: reason={$final->end_reason->value} dakika={$final->duration_minutes} ended_at={$final->ended_at}\n");

        Carbon::setTestNow();
        $this->assertTrue(true);
    }

    /** PROBE 2: onerilen kosullu update SQLite'ta calisiyor mu (enum + Carbon binding) */
    public function test_probe_conditional_update(): void
    {
        $ogr = $this->ogrenci();
        $s = $this->oturum($ogr, $this->yerel('2026-09-21 05:00'));

        $bitis = Carbon::parse('2026-09-21 14:00:00', 'UTC');

        $etkilenen = StudySession::whereKey($s->id)->whereNull('ended_at')->update([
            'ended_at' => $bitis,
            'duration_minutes' => 120,
            'end_reason' => SessionEndReason::OverLimit,
        ]);
        fwrite(STDERR, "\nILK KOSULLU UPDATE etkilenen={$etkilenen}\n");

        $row = DB::table('study_sessions')->where('id', $s->id)->first();
        fwrite(STDERR, "HAM SATIR: ended_at={$row->ended_at} reason={$row->end_reason} updated_at={$row->updated_at}\n");

        // ikinci kapatma denemesi: 0 satir
        $ikinci = StudySession::whereKey($s->id)->whereNull('ended_at')->update([
            'ended_at' => Carbon::parse('2026-09-21 20:00:00', 'UTC'),
            'duration_minutes' => 900,
            'end_reason' => SessionEndReason::Switched,
        ]);
        fwrite(STDERR, "IKINCI KOSULLU UPDATE etkilenen={$ikinci}\n");

        $row2 = DB::table('study_sessions')->where('id', $s->id)->first();
        fwrite(STDERR, "HAM SATIR 2: ended_at={$row2->ended_at} reason={$row2->end_reason}\n");

        // save() ile ayni bicim mi yaziliyor? karsilastir
        $s2 = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 05:00'));
        $s2->forceFill(['ended_at' => $bitis, 'duration_minutes' => 120, 'end_reason' => SessionEndReason::OverLimit])->save();
        $row3 = DB::table('study_sessions')->where('id', $s2->id)->first();
        fwrite(STDERR, "SAVE ILE: ended_at={$row3->ended_at} reason={$row3->end_reason}\n");

        $this->assertTrue(true);
    }
}
