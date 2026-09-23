<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudyStats;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Onayli toplamlar NET sure (QA hata 6).
 *
 * Sayac "Net calisma · molalar sayilmaz" diyor ve duration_minutes net
 * yaziliyor; StudyStats ise baslangic-bitis farkini topluyordu. Ogle arasi
 * onaydan sonra calisma gibi sayiliyordu: panel, rapor, veli ayni yanlis
 * sayiyi goruyordu.
 */
class StudyStatsNetTimeTest extends TestCase
{
    use RefreshDatabase;

    private function yerel(string $zaman): Carbon
    {
        return Carbon::parse($zaman, config('kafe.timezone'))->utc();
    }

    private function ogrenci(): User
    {
        return User::factory()->create(['role' => Role::Student->value, 'subscription_status' => 'active']);
    }

    /** Onayli, kapali oturum; molalar [bas, bit] ciftleri (yerel saat). */
    private function oturum(User $ogrenci, string $bas, string $bit, array $molalar = [], ?int $dersId = null): StudySession
    {
        $oturum = StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'subject_id' => $dersId,
            'started_at' => $this->yerel($bas),
            'ended_at' => $this->yerel($bit),
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);

        foreach ($molalar as [$mb, $ms]) {
            $oturum->pauses()->create(['kind' => 'lunch', 'started_at' => $this->yerel($mb), 'ended_at' => $this->yerel($ms)]);
        }

        // Kapanista yazilan net sure - gercek akistaki gibi.
        $oturum->update(['duration_minutes' => $oturum->fresh('pauses')->minutesSoFar($this->yerel($bit))]);

        return $oturum;
    }

    private function istatistik(): StudyStats
    {
        return app(StudyStats::class);
    }

    public function test_today_week_and_month_are_net_of_the_lunch_break(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-29 09:00', '2026-09-29 12:00', [['2026-09-29 10:00', '2026-09-29 11:00']]);
        $this->travelTo($this->yerel('2026-09-29 14:00'));

        $this->assertSame(120, $this->istatistik()->todayMinutes($ogrenci));
        $this->assertSame(120, $this->istatistik()->weekMinutes($ogrenci));
        $this->assertSame(120, $this->istatistik()->monthMinutes($ogrenci));
    }

    public function test_the_subject_breakdown_is_net_of_breaks(): void
    {
        $ogrenci = $this->ogrenci();
        $ders = Subject::create(['name' => 'Matematik', 'exam_type' => 'tyt']);
        $this->oturum($ogrenci, '2026-09-29 09:00', '2026-09-29 12:00', [['2026-09-29 10:00', '2026-09-29 11:00']], $ders->id);

        $kirilim = $this->istatistik()->minutesBySubject($ogrenci, ...LocalDay::weekBounds('2026-09-29'));

        $this->assertSame(['Matematik' => 120], $kirilim);
    }

    /** Gece yarisini asan mola da gunlere bolunur: her gunden kendi payi duser. */
    public function test_a_break_crossing_midnight_is_split_between_the_days(): void
    {
        $ogrenci = $this->ogrenci();
        // 22:00-02:00 (240 dk), mola 23:30-00:30: gun 1'e 90, gun 2'ye 90.
        $this->oturum($ogrenci, '2026-09-14 22:00', '2026-09-15 02:00', [['2026-09-14 23:30', '2026-09-15 00:30']]);

        $this->assertSame(90, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
        $this->assertSame(90, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-15'));

        $gunluk = $this->istatistik()->dailyMinutesForMany([$ogrenci->id], '2026-09-14', '2026-09-15');
        $this->assertSame(['2026-09-14' => 90, '2026-09-15' => 90], $gunluk[$ogrenci->id]);
    }

    /** Tek pencerede kalan oturumun toplami, kapanista yazilan sureye esit. */
    public function test_a_session_inside_one_window_equals_its_duration_minutes(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 13:17', [
            ['2026-09-14 10:40', '2026-09-14 10:55'],
            ['2026-09-14 12:00', '2026-09-14 12:07'],
        ]);

        $this->assertSame($oturum->duration_minutes, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
        $this->assertSame(
            [$ogrenci->id => ['2026-09-14' => $oturum->duration_minutes]],
            $this->istatistik()->dailyMinutesForMany([$ogrenci->id], '2026-09-14', '2026-09-14'),
        );
    }
}
