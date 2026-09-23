<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tembel kapatma yalnizca BAYAT OLABILECEK oturumlari okur (QA perf P5).
 *
 * closeStale() her istekte butun acik oturumlari (32 masaya kadar) cekip
 * PHP'de eliyordu. Aday suzgeci artik SQL'de; karar yine isStale() ile
 * birebir ayni olmali.
 */
class SessionCloserCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function yerel(string $zaman): Carbon
    {
        return Carbon::parse($zaman, config('kafe.timezone'));
    }

    private function acik(string $yerelBaslangic): StudySession
    {
        return StudySession::create([
            'student_id' => User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create()->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $this->yerel($yerelBaslangic)->utc(),
        ]);
    }

    public function test_fresh_open_sessions_are_not_loaded_at_all(): void
    {
        foreach (['2026-09-29 09:00', '2026-09-29 11:30', '2026-09-29 13:59'] as $bas) {
            $this->acik($bas);
        }

        $okunan = 0;
        Event::listen('eloquent.retrieved: ' . StudySession::class, function () use (&$okunan) {
            $okunan++;
        });

        $this->assertSame(0, app(SessionCloser::class)->closeStale($this->yerel('2026-09-29 14:00')->utc()));
        $this->assertSame(0, $okunan);
    }

    /** @return array<string,array{0:string,1:string}> baslangic, simdi (yerel) */
    public static function sinirlar(): array
    {
        return [
            'kapanistan bir saniye once basladi, kapanis gecti' => ['2026-09-28 20:59:59', '2026-09-29 09:05'],
            'kapanistan bir saniye once basladi, kapanis henuz degil' => ['2026-09-29 20:59:59', '2026-09-29 20:59:59'],
            'tam kapanista basladi, 12 saat doldu' => ['2026-09-28 21:00', '2026-09-29 09:00'],
            'tam kapanista basladi, 12 saat dolmadi' => ['2026-09-28 21:00', '2026-09-29 08:59:59'],
            'gece yarisindan sonra basladi, 12 saat doldu' => ['2026-09-29 00:30', '2026-09-29 12:30'],
            'gece yarisindan sonra basladi, 12 saat dolmadi' => ['2026-09-29 00:30', '2026-09-29 12:29'],
            'gunduz, tam kapanis ani' => ['2026-09-29 10:00', '2026-09-29 21:00'],
            'gunduz, kapanistan once' => ['2026-09-29 10:00', '2026-09-29 20:59'],
            'iki gun onceki unutulmus oturum' => ['2026-09-27 10:00', '2026-09-29 14:00'],
        ];
    }

    #[DataProvider('sinirlar')]
    public function test_the_sql_filter_closes_exactly_what_is_stale(string $baslangic, string $simdi): void
    {
        $oturum = $this->acik($baslangic);
        $an = $this->yerel($simdi)->utc();
        $kapatici = app(SessionCloser::class);
        $bayat = $kapatici->isStale($oturum->fresh(), $an);

        $this->assertSame($bayat ? 1 : 0, $kapatici->closeStale($an));
        $this->assertSame($bayat, $oturum->fresh()->ended_at !== null);
    }
}
