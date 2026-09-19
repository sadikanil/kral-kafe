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

class ZzTempOverwriteTest extends TestCase
{
    use RefreshDatabase;

    private function yerel(string $z): Carbon
    {
        return Carbon::parse($z, config('kafe.timezone'));
    }

    private function kur(): array
    {
        $ogrenci = User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $oturum = StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $masa->id,
            'started_at' => $this->yerel('2026-09-21 08:00')->copy()->utc(),
        ]);

        return [$ogrenci, $masa, $oturum];
    }

    public function test_A_closeStale_sonrasi_close_uzerine_yaziyor_mu(): void
    {
        [$ogrenci] = $this->kur();

        $service = app(StudySessionService::class);
        $closer = app(SessionCloser::class);

        // now = yerel 20:30. dueEnd = 08:00 + 12s = 20:00 -> OverLimit, 720 dk.
        Carbon::setTestNow($this->yerel('2026-09-21 20:30'));

        // R1: istegin kontrolcusu acik oturumu OKUDU.
        $r1 = $service->openFor($ogrenci);
        $this->assertNotNull($r1, 'R1 acik oturumu okuyamadi');

        // R2: cron / baska istek bu arada kapatti.
        $kapatilan = $closer->closeStale();
        $this->assertSame(1, $kapatilan);

        $araDurum = StudySession::first()->refresh();
        fwrite(STDERR, "\n--- R2 sonrasi ---\n");
        fwrite(STDERR, 'sebep: ' . ($araDurum->end_reason?->value ?? 'NULL') . '  sure: ' . $araDurum->duration_minutes . "\n");

        // R1: elindeki BAYAT modelle manuel kapatiyor.
        DB::enableQueryLog();
        $service->close($r1, SessionEndReason::Manual);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($log as $q) {
            if (str_starts_with(strtolower(trim($q['query'])), 'update')) {
                fwrite(STDERR, "R1 UPDATE: " . $q['query'] . "\n");
            }
        }

        $son = StudySession::first()->refresh();
        fwrite(STDERR, "--- R1 sonrasi ---\n");
        fwrite(STDERR, 'son sebep: ' . ($son->end_reason?->value ?? 'NULL') . "  (over_limit bekleniyordu)\n");
        fwrite(STDERR, 'son sure : ' . $son->duration_minutes . "  (720 bekleniyordu)\n\n");

        $this->assertSame(SessionEndReason::OverLimit, $son->end_reason, 'ANOMALI SILINDI');
        $this->assertSame(720, $son->duration_minutes, 'SURE SISTI');
    }

    public function test_B_start_switched_uzerine_yaziyor_mu(): void
    {
        [$ogrenci, $masa] = $this->kur();
        $masa2 = StudyTable::create(['name' => 'Masa 2']);

        $service = app(StudySessionService::class);
        $closer = app(SessionCloser::class);

        Carbon::setTestNow($this->yerel('2026-09-21 20:30'));

        // start() transaction icinde once okur, sonra close(Switched) yazar.
        // Arada closeStale kosarsa ayni uzerine yazma olusur mu?
        $acik = $service->openFor($ogrenci);
        $closer->closeStale();
        $service->close($acik, SessionEndReason::Switched);

        $son = StudySession::orderBy('id')->first()->refresh();
        fwrite(STDERR, "\n[B] son sebep: " . ($son->end_reason?->value ?? 'NULL') . " sure: " . $son->duration_minutes . "\n");

        $this->assertSame(SessionEndReason::OverLimit, $son->end_reason);
    }

    public function test_C_web_yolu_middleware_ile_korunuyor_mu(): void
    {
        [$ogrenci] = $this->kur();

        // Oturum ZATEN bayat iken ogrenci "Bitir"e basiyor.
        Carbon::setTestNow($this->yerel('2026-09-21 20:30'));

        $this->actingAs($ogrenci)->post(route('session.end'));

        $son = StudySession::first()->refresh();
        fwrite(STDERR, "\n[C] web yolu -> sebep: " . ($son->end_reason?->value ?? 'NULL') . " sure: " . $son->duration_minutes . "\n");

        $this->assertSame(SessionEndReason::OverLimit, $son->end_reason, 'web yolu da anomaliyi sildi');
        $this->assertSame(720, $son->duration_minutes);
    }
}
