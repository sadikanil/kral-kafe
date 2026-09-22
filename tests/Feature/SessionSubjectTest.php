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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dalga 17a - Oturuma ders etiketi.
 *
 * ZORUNLU DEGIL. Etiketsiz oturum "Genel" sayilir; zorunlu kilmak masaya
 * oturmanin onune bir soru koyardi ve ogrenci okutmayi birakirdi.
 *
 * Degeri: "12 saat calisti" yerine "8 saat matematik, 0 saat Turkce".
 * Plan (SS7-D) ve zayif konu (SS7-H) ile ayni subjects tablosuna bagli.
 */
class SessionSubjectTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function ders(string $ad): Subject
    {
        return Subject::create(['name' => $ad, 'exam_type' => 'tyt']);
    }

    private function acikOturum(User $ogrenci): StudySession
    {
        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => now()->subHour(),
        ]);
    }

    private function bitmisOturum(User $ogrenci, string $gun, int $dakika, ?Subject $ders = null): StudySession
    {
        $bas = Carbon::parse($gun . ' 10:00', config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addMinutes($dakika)->utc(),
            'duration_minutes' => $dakika,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
            'subject_id' => $ders?->id,
        ]);
    }

    // --- Semanin korunmasi ---------------------------------------------------

    /**
     * KISMI INDEKS AYAKTA KALMALI.
     *
     * study_sessions'a foreignId()->constrained() ile sutun eklemek SQLite'ta
     * tabloyu bastan yazar ve ham SQL ile kurulmus
     * "WHERE ended_at IS NULL" indeksini sessizce dusurur - "bir ogrencinin
     * tek ACIK oturumu" kurali "tek oturumu"na donusurdu. Bu tam olarak bir
     * kez oldu (README SS10.5).
     */
    public function test_the_partial_open_session_index_survives_the_migration(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Kismi indeksin bicimi surucuye ozgu.');
        }

        $tanim = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE name = 'study_sessions_tek_acik_oturum'"
        );

        $this->assertNotNull($tanim, 'Kismi indeks kaybolmus');
        $this->assertStringContainsString('ended_at IS NULL', $tanim->sql);
    }

    // --- Etiketleme ----------------------------------------------------------

    public function test_a_student_labels_their_open_session(): void
    {
        $ogrenci = $this->ogrenci();
        $ders = $this->ders('Matematik');
        $oturum = $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)
            ->post(route('session.subject', $oturum), ['subject_id' => $ders->id])
            ->assertRedirect();

        $this->assertSame($ders->id, $oturum->fresh()->subject_id);
    }

    /** Etiket DEGISTIRILEBILIR: ogrenci dersi ortada degistirebilir. */
    public function test_the_label_can_be_changed(): void
    {
        $ogrenci = $this->ogrenci();
        $matematik = $this->ders('Matematik');
        $turkce = $this->ders('Türkçe');
        $oturum = $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->post(route('session.subject', $oturum), ['subject_id' => $matematik->id]);
        $this->actingAs($ogrenci)->post(route('session.subject', $oturum), ['subject_id' => $turkce->id]);

        $this->assertSame($turkce->id, $oturum->fresh()->subject_id);
    }

    public function test_the_label_can_be_cleared(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);
        $this->actingAs($ogrenci)->post(route('session.subject', $oturum), ['subject_id' => $this->ders('Matematik')->id]);

        $this->actingAs($ogrenci)->post(route('session.subject', $oturum), ['subject_id' => '']);

        $this->assertNull($oturum->fresh()->subject_id);
    }

    public function test_a_student_cannot_label_someone_elses_session(): void
    {
        $sahibi = $this->ogrenci();
        $baskasi = $this->ogrenci();
        $oturum = $this->acikOturum($sahibi);

        $this->actingAs($baskasi)
            ->post(route('session.subject', $oturum), ['subject_id' => $this->ders('Matematik')->id])
            ->assertForbidden();

        $this->assertNull($oturum->fresh()->subject_id);
    }

    /**
     * BITMIS oturum etiketlenemez.
     *
     * Sure onaya gitmis olabilir; gecmisi sonradan yeniden etiketlemek
     * kirilimi velinin gordugu rapordan sonra degistirirdi.
     */
    public function test_a_finished_session_cannot_be_labelled(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->bitmisOturum($ogrenci, '2026-09-15', 120);

        $this->actingAs($ogrenci)
            ->post(route('session.subject', $oturum), ['subject_id' => $this->ders('Matematik')->id])
            ->assertForbidden();
    }

    public function test_an_unknown_subject_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->from(route('user.dashboard'))
            ->post(route('session.subject', $oturum), ['subject_id' => 9999])
            ->assertSessionHasErrors('subject_id');
    }

    // --- Kirilim -------------------------------------------------------------

    public function test_the_breakdown_sums_minutes_per_subject(): void
    {
        $ogrenci = $this->ogrenci();
        $matematik = $this->ders('Matematik');
        $turkce = $this->ders('Türkçe');

        $this->bitmisOturum($ogrenci, '2026-09-15', 120, $matematik);
        $this->bitmisOturum($ogrenci, '2026-09-16', 60, $matematik);
        $this->bitmisOturum($ogrenci, '2026-09-17', 90, $turkce);

        [$bas, $son] = \App\Support\LocalDay::weekBounds('2026-09-16');
        $kirilim = app(StudyStats::class)->minutesBySubject($ogrenci, $bas, $son);

        $this->assertSame(['Matematik' => 180, 'Türkçe' => 90], $kirilim);
    }

    /** Etiketsiz oturum "Genel" kovasina duser - kaybolmaz. */
    public function test_an_unlabelled_session_lands_in_the_general_bucket(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bitmisOturum($ogrenci, '2026-09-15', 120, $this->ders('Matematik'));
        $this->bitmisOturum($ogrenci, '2026-09-16', 45);

        [$bas, $son] = \App\Support\LocalDay::weekBounds('2026-09-16');
        $kirilim = app(StudyStats::class)->minutesBySubject($ogrenci, $bas, $son);

        $this->assertSame(['Matematik' => 120, 'Genel' => 45], $kirilim);
    }

    /** Kirilim de YALNIZCA onaylı oturumu sayar - diger tum istatistikler gibi. */
    public function test_the_breakdown_counts_only_approved_sessions(): void
    {
        $ogrenci = $this->ogrenci();
        $matematik = $this->ders('Matematik');

        $this->bitmisOturum($ogrenci, '2026-09-15', 120, $matematik);
        $this->bitmisOturum($ogrenci, '2026-09-16', 200, $matematik)
            ->update(['approval_status' => ApprovalStatus::Pending->value]);

        [$bas, $son] = \App\Support\LocalDay::weekBounds('2026-09-16');

        $this->assertSame(['Matematik' => 120], app(StudyStats::class)->minutesBySubject($ogrenci, $bas, $son));
    }

    public function test_a_week_without_sessions_has_an_empty_breakdown(): void
    {
        [$bas, $son] = \App\Support\LocalDay::weekBounds('2026-09-16');

        $this->assertSame([], app(StudyStats::class)->minutesBySubject($this->ogrenci(), $bas, $son));
    }

    // --- Ekranda -------------------------------------------------------------

    public function test_the_open_session_card_offers_the_subjects(): void
    {
        $ogrenci = $this->ogrenci();
        $this->ders('Matematik');
        $this->acikOturum($ogrenci);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Ne çalışıyorsun?')
            ->assertSee('Matematik');
    }

    public function test_the_dashboard_shows_this_weeks_breakdown(): void
    {
        $ogrenci = $this->ogrenci();
        $this->travelTo(Carbon::parse('2026-09-16 18:00', config('kafe.timezone')));
        $this->bitmisOturum($ogrenci, '2026-09-15', 120, $this->ders('Matematik'));

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Ders kırılımı')
            ->assertSee('Matematik');
    }
}
