<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\ExamEvent;
use App\Models\Notification;
use App\Models\StudentParent;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\NotificationBuilder;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 11 - Bildirim altyapisi.
 *
 * Su an teslim kanali YALNIZCA PANEL: kayitlar uretiliyor ve veli/ogrenci
 * panelinde listeleniyor. E-posta sonra gelecek ve ayni kayitlarin uzerine
 * binecek - bu yuzden kayit ile teslim bastan ayri tutuluyor.
 *
 * Uretim cron'a bagli cunku "gun sonunda" ve "denemeden bir gun once" tembel
 * uretimle cozulemez: rapor hesaplanabilir ama bildirim GONDERILMIS ya da
 * gonderilmemistir, sonradan turetilemez.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function veli(User $ogrenci): User
    {
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create([
            'student_id' => $ogrenci->id,
            'parent_id' => $veli->id,
        ]);

        return $veli;
    }

    private function oturum(User $ogrenci, string $gun, ApprovalStatus $onay = ApprovalStatus::Approved): StudySession
    {
        $bas = Carbon::parse($gun . ' 10:00', config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addHours(3)->utc(),
            'duration_minutes' => 180,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => $onay->value,
        ]);
    }

    private function uretici(): NotificationBuilder
    {
        return app(NotificationBuilder::class);
    }

    // --- Devamsizlik --------------------------------------------------------

    /**
     * ONAY BEKLEYEN OTURUM DA GELIS SAYILIR.
     *
     * Devamsizligi StudyStats uzerinden olcseydik (o yalnizca onayliyi sayar),
     * ogrenci gelir, yonetici henuz onaylamamis olur ve velisine "gelmedi"
     * bildirimi giderdi. Onay bir MUHASEBE karari; ogrencinin kafede olup
     * olmadigi ondan bagimsiz bir olgu.
     */
    public function test_a_session_awaiting_approval_still_counts_as_attendance(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);

        $this->oturum($ogrenci, '2026-09-14');                       // gecen hafta gelmis
        $this->oturum($ogrenci, '2026-09-16', ApprovalStatus::Pending); // bugun geldi, onay bekliyor

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_parent_is_told_when_the_student_did_not_come(): void
    {
        $ogrenci = $this->ogrenci('Gelmeyen');
        $veli = $this->veli($ogrenci);

        $this->oturum($ogrenci, '2026-09-14'); // o hafta bir kez geldi

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $veli->id,
            'type' => NotificationType::Absence->value,
        ]);
    }

    /**
     * O hafta hic gelmemis ogrencinin velisine her gun mesaj gitmez.
     *
     * Tatildeki ya da kaydi donmus ogrencinin velisi icin bildirim gurultuye
     * doner; gurultuye donen bildirim okunmaz hale gelir ve asil onemli olani
     * da goturur.
     */
    public function test_no_notice_when_the_student_has_not_come_all_week(): void
    {
        $ogrenci = $this->ogrenci();
        $this->veli($ogrenci);

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());

        $this->assertDatabaseCount('notifications', 0);
    }

    /**
     * Cron iki kez calisirsa ikinci bildirim olusmaz. Vercel Hobby'de sapma
     * ±59 dakika; yeniden deneme ve elle tetikleme de mumkun.
     */
    public function test_running_twice_does_not_duplicate_a_notice(): void
    {
        $ogrenci = $this->ogrenci();
        $this->veli($ogrenci);
        $this->oturum($ogrenci, '2026-09-14');

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());
        $this->uretici()->absenceNotices(LocalDay::today());

        $this->assertDatabaseCount('notifications', 1);
    }

    // --- Deneme hatirlatmasi ------------------------------------------------

    public function test_a_reminder_goes_out_the_day_before_an_exam(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli($ogrenci);

        $deneme = ExamEvent::create([
            'title' => 'TYT Deneme 4',
            'exam_type' => 'tyt',
            'exam_date' => '2026-09-17',
        ]);

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->examReminders(LocalDay::today());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $ogrenci->id,
            'type' => NotificationType::ExamTomorrow->value,
            'related_id' => $deneme->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $veli->id,
            'type' => NotificationType::ExamTomorrow->value,
        ]);
    }

    public function test_no_reminder_for_an_exam_further_away(): void
    {
        $ogrenci = $this->ogrenci();
        $this->veli($ogrenci);

        ExamEvent::create([
            'title' => 'TYT Deneme 5',
            'exam_type' => 'tyt',
            'exam_date' => '2026-09-25',
        ]);

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->examReminders(LocalDay::today());

        $this->assertDatabaseCount('notifications', 0);
    }

    // --- Cron ucu -----------------------------------------------------------

    public function test_the_cron_endpoint_refuses_a_request_without_the_secret(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->get(route('cron.daily'))->assertForbidden();
    }

    public function test_the_cron_endpoint_runs_with_the_secret(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);

        // Vercel Cron ozel baslik GONDEREMEZ; tek tasidigi sey
        // "Authorization: Bearer <CRON_SECRET>".
        $this->withToken('gizli')
            ->get(route('cron.daily'))
            ->assertOk();
    }

    public function test_the_cron_endpoint_refuses_a_wrong_secret(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->withToken('yanlis')
            ->get(route('cron.daily'))
            ->assertForbidden();
    }

    /**
     * Vercel PHP'yi /api/index.php uzerinden calistiriyor; Symfony bu yuzden
     * /api onekini kok dizin sayip yoldan KESIYOR. /api/... altindaki bir
     * rota canlida hic eslesmez (Dalga 11'in cron'u bu yuzden hic
     * calismadi). vercel.json'daki her cron yolu gercek bir rotaya denk
     * gelmeli ve /api ile baslamamali.
     */
    public function test_every_vercel_cron_path_hits_a_real_route_outside_api(): void
    {
        $vercel = json_decode(file_get_contents(base_path('vercel.json')), true);

        $this->assertNotEmpty($vercel['crons']);

        foreach ($vercel['crons'] as $cron) {
            $this->assertStringStartsNotWith('/api/', $cron['path']);

            $rota = app('router')->getRoutes()->match(
                \Illuminate\Http\Request::create($cron['path'], 'GET')
            );

            $this->assertSame('cron.daily', $rota->getName());
        }
    }

    /**
     * Anahtar tanimlanmamissa uc HIC calismaz. "Anahtar yoksa herkese acik"
     * varsayilani, degiskeni girmeyi unutan bir dagitimda ucu internete
     * acardi.
     */
    public function test_the_cron_endpoint_is_closed_when_no_secret_is_configured(): void
    {
        config(['kafe.cron_anahtari' => '']);

        $this->withToken('')
            ->get(route('cron.daily'))
            ->assertForbidden();
    }

    // --- Panelde gorunum ----------------------------------------------------

    public function test_a_parent_sees_their_notifications_in_the_panel(): void
    {
        $ogrenci = $this->ogrenci('Gelmeyen Çocuk');
        $veli = $this->veli($ogrenci);
        $this->oturum($ogrenci, '2026-09-14');

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());

        // Cocugun ADI zaten panelde (kendi karti); bildirimin GOVDESINI
        // ariyoruz ki test gercekten bildirim listesini olcsun.
        $this->actingAs($veli)->get('/veli')
            ->assertOk()
            ->assertSee('bugün kafeye gelmedi');
    }

    public function test_a_parent_does_not_see_another_parents_notification(): void
    {
        $ogrenci = $this->ogrenci('Başkasının Çocuğu');
        $this->veli($ogrenci);
        $this->oturum($ogrenci, '2026-09-14');

        $yabanci = User::factory()->parent()->create();

        $this->travelTo(Carbon::parse('2026-09-16 22:00', config('kafe.timezone')));
        $this->uretici()->absenceNotices(LocalDay::today());

        $this->assertSame(0, Notification::where('user_id', $yabanci->id)->count());
    }
}
