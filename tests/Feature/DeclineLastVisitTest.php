<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\DeclineSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "N gundur gelmedi" sinyalinin son gelis gunu (QA perf P12).
 *
 * Son gelis artik veritabaninda max(ended_at) ile bulunuyor - ogrencinin
 * butun gecmisini PHP'ye tasimak okul yili boyunca buyuyordu. Ham deger UTC;
 * gun yine KAFE saatine gore sayilmali.
 */
class DeclineLastVisitTest extends TestCase
{
    use RefreshDatabase;

    private function yerel(string $zaman): Carbon
    {
        return Carbon::parse($zaman, config('kafe.timezone'))->utc();
    }

    private function gelis(User $ogrenci, string $bas, string $bit, ApprovalStatus $onay = ApprovalStatus::Approved): void
    {
        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::firstOrCreate(['name' => 'Masa 1'])->id,
            'started_at' => $this->yerel($bas), 'ended_at' => $this->yerel($bit),
            'duration_minutes' => (int) $this->yerel($bas)->diffInMinutes($this->yerel($bit)),
            'end_reason' => SessionEndReason::Manual->value, 'approval_status' => $onay->value,
        ]);
    }

    private function devamsizlik(User $ogrenci): ?string
    {
        foreach (app(DeclineSignals::class)->for($ogrenci) as $sinyal) {
            if ($sinyal->kind === 'absence') {
                return $sinyal->label;
            }
        }

        return null;
    }

    public function test_the_latest_countable_visit_in_cafe_time_sets_the_gap(): void
    {
        $this->travelTo(Carbon::parse('2026-09-22 10:00', config('kafe.timezone')));
        $ogrenci = User::factory()->student()->create();

        $this->gelis($ogrenci, '2026-09-10 10:00', '2026-09-10 12:00');
        // Yerel 19 Eylul 01:00'de biten gece oturumu - UTC'de 18 Eylul 22:00.
        $this->gelis($ogrenci, '2026-09-18 23:00', '2026-09-19 01:00');
        // Onaysiz ve cok kisa oturumlar gelis sayilmaz.
        $this->gelis($ogrenci, '2026-09-21 10:00', '2026-09-21 12:00', ApprovalStatus::Pending);
        $this->gelis($ogrenci, '2026-09-21 14:00', '2026-09-21 14:01');

        $this->assertSame('3 gündür gelmedi', $this->devamsizlik($ogrenci));
    }
}
