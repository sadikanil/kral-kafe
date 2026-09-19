<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZzRaceProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_race_loser_path(): void
    {
        $ogrenci = User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
        $a = StudyTable::create(['name' => 'Masa A']);
        $b = StudyTable::create(['name' => 'Masa B']);

        // Yaris KAZANANI: baska bir istek zaten acik oturumu yazdi.
        $kazanan = StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => $a->id,
            'started_at' => now(),
        ]);

        // Kaybeden istek: ilk openFor() cagrisi (INSERT oncesi) null gordu.
        $servis = new class extends StudySessionService {
            public int $cagri = 0;
            public function openFor(User $student): ?StudySession
            {
                $this->cagri++;
                return $this->cagri === 1 ? null : parent::openFor($student);
            }
        };

        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = ['sql' => $q->sql, 'level' => DB::transactionLevel()];
        });

        $sonuc = null;
        $hata = null;
        try {
            $sonuc = $servis->start($ogrenci, $b);
        } catch (\Throwable $e) {
            $hata = get_class($e).': '.substr($e->getMessage(), 0, 120);
        }

        fwrite(STDERR, "\n===== SORGU AKISI (surucu: ".DB::connection()->getDriverName().") =====\n");
        foreach ($log as $i => $satir) {
            fwrite(STDERR, sprintf("%2d [tx=%d] %s\n", $i, $satir['level'], substr($satir['sql'], 0, 95)));
        }
        fwrite(STDERR, "SAVEPOINT gecen sorgu sayisi: ".count(array_filter($log, fn ($r) => str_contains(strtoupper($r['sql']), 'SAVEPOINT')))."\n");
        fwrite(STDERR, "hata: ".($hata ?? 'yok')."\n");
        fwrite(STDERR, "donen oturum id: ".($sonuc?->id ?? 'null')." / kazanan id: {$kazanan->id}\n");
        fwrite(STDERR, "acik oturum sayisi: ".StudySession::whereNull('ended_at')->count()."\n");
        fwrite(STDERR, "=====================================\n");

        $this->assertTrue(true);
    }
}
