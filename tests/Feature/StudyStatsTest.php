<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudyStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 5 - Sure, devamlilik, hedef (MVP #4, #6, #5).
 *
 * Isin tamami bir hesap problemi ve iki tuzagi var:
 *
 *   1. Gun siniri YEREL. Sutunlar UTC; Istanbul'da 01:00'de biten oturum UTC'de
 *      bir onceki gune ait. whereDate ile sayarsan ogrencinin gece calismasi
 *      yanlis gune duser.
 *   2. Gece yarisini asan oturum TEK satir. Gune bolme hesap tarafinda yapilir;
 *      22:00-01:30 arasi bir oturum birinci gune 120, ikinci gune 90 dakika
 *      yazmali. Toplami 210 olan tek bir kayda "hangi gun" demek mumkun degil.
 */
class StudyStatsTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function yerel(string $zaman): Carbon
    {
        return Carbon::parse($zaman, config('kafe.timezone'));
    }

    /** Kapali oturum. Saatler YEREL verilir, UTC saklanir. */
    private function oturum(User $ogrenci, string $bas, ?string $bit = null): StudySession
    {
        $baslangic = $this->yerel($bas);
        $bitis = $bit ? $this->yerel($bit) : null;

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $baslangic->copy()->utc(),
            'ended_at' => $bitis?->copy()->utc(),
            'duration_minutes' => $bitis ? (int) $baslangic->diffInMinutes($bitis) : null,
            'end_reason' => $bitis ? SessionEndReason::Manual->value : null,
        ]);
    }

    private function istatistik(): StudyStats
    {
        return app(StudyStats::class);
    }

    public function test_a_session_inside_one_day_counts_fully(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 13:30');

        $this->assertSame(210, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
    }

    /**
     * Gunun %12,5'i yanlis kovaya dusuyordu: Istanbul UTC+3, yani yerel
     * 00:00-03:00 arasi UTC'de bir onceki gun.
     */
    public function test_a_late_night_session_belongs_to_the_local_day(): void
    {
        $ogrenci = $this->ogrenci();
        // Yerel 01:00-02:00 -> UTC'de 2026-09-13 22:00-23:00
        $this->oturum($ogrenci, '2026-09-14 01:00', '2026-09-14 02:00');

        $this->assertSame(60, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
        $this->assertSame(0, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-13'));
    }

    /** Tek satir, iki gun. */
    public function test_a_session_crossing_midnight_is_split_between_days(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-14 22:00', '2026-09-15 01:30');

        $this->assertSame(120, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
        $this->assertSame(90, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-15'));
    }

    public function test_an_open_session_counts_up_to_now(): void
    {
        $ogrenci = $this->ogrenci();

        Carbon::setTestNow($this->yerel('2026-09-14 16:00'));
        $this->oturum($ogrenci, '2026-09-14 14:30');

        $this->assertSame(90, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
        Carbon::setTestNow();
    }

    /** Yanlis okutma istatistige girmemeli - Dalga 3'teki kuralin devami. */
    public function test_a_very_short_session_is_ignored(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 10:01');

        $this->assertSame(0, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
    }

    public function test_another_students_session_is_not_counted(): void
    {
        $ogrenci = $this->ogrenci();
        $baskasi = $this->ogrenci();
        $this->oturum($baskasi, '2026-09-14 10:00', '2026-09-14 12:00');

        $this->assertSame(0, $this->istatistik()->minutesOnDay($ogrenci, '2026-09-14'));
    }

    /** Hafta pazartesi baslar (FEATURE 3). 14 Eylul 2026 pazartesi. */
    public function test_the_week_starts_on_monday(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-13 10:00', '2026-09-13 12:00'); // pazar, onceki hafta
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 11:00'); // pazartesi
        $this->oturum($ogrenci, '2026-09-20 20:00', '2026-09-20 21:00'); // pazar, ayni hafta

        Carbon::setTestNow($this->yerel('2026-09-16 12:00'));
        $this->assertSame(120, $this->istatistik()->weekMinutes($ogrenci));
        Carbon::setTestNow();
    }

    public function test_month_totals_use_local_month_boundaries(): void
    {
        $ogrenci = $this->ogrenci();
        // Yerel 1 Ekim 01:00 -> UTC'de 30 Eylul. Ekim'e sayilmali.
        $this->oturum($ogrenci, '2026-10-01 01:00', '2026-10-01 02:00');
        $this->oturum($ogrenci, '2026-09-30 22:00', '2026-09-30 23:00');

        Carbon::setTestNow($this->yerel('2026-10-05 12:00'));
        $this->assertSame(60, $this->istatistik()->monthMinutes($ogrenci));
        Carbon::setTestNow();
    }

    public function test_attended_days_lists_only_days_that_pass_the_threshold(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 12:00');
        $this->oturum($ogrenci, '2026-09-15 10:00', '2026-09-15 10:01'); // cok kisa
        $this->oturum($ogrenci, '2026-09-16 09:00', '2026-09-16 09:30');

        $gunler = $this->istatistik()->attendedDays($ogrenci, '2026-09-14', '2026-09-16');

        $this->assertSame(['2026-09-14', '2026-09-16'], $gunler);
    }

    public function test_the_streak_counts_consecutive_days_up_to_today(): void
    {
        $ogrenci = $this->ogrenci();
        foreach (['2026-09-16', '2026-09-17', '2026-09-18'] as $gun) {
            $this->oturum($ogrenci, "{$gun} 10:00", "{$gun} 12:00");
        }

        Carbon::setTestNow($this->yerel('2026-09-18 20:00'));
        $this->assertSame(3, $this->istatistik()->streak($ogrenci));
        Carbon::setTestNow();
    }

    /**
     * Ogrenci bugun daha gelmemis olabilir; bu dunku seriyi SIFIRLAMAZ.
     * Aksi halde seri her sabah sifirlanir ve ozellik anlamsizlasir.
     */
    public function test_not_having_arrived_yet_today_does_not_break_the_streak(): void
    {
        $ogrenci = $this->ogrenci();
        foreach (['2026-09-16', '2026-09-17'] as $gun) {
            $this->oturum($ogrenci, "{$gun} 10:00", "{$gun} 12:00");
        }

        Carbon::setTestNow($this->yerel('2026-09-18 09:00'));
        $this->assertSame(2, $this->istatistik()->streak($ogrenci));
        Carbon::setTestNow();
    }

    public function test_a_gap_breaks_the_streak(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, '2026-09-14 10:00', '2026-09-14 12:00');
        $this->oturum($ogrenci, '2026-09-17 10:00', '2026-09-17 12:00');
        $this->oturum($ogrenci, '2026-09-18 10:00', '2026-09-18 12:00');

        Carbon::setTestNow($this->yerel('2026-09-18 20:00'));
        $this->assertSame(2, $this->istatistik()->streak($ogrenci));
        Carbon::setTestNow();
    }
}
