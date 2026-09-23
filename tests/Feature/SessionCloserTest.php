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
use Tests\TestCase;

/**
 * Dalga 4 - Otomatik kapanis (MVP #3).
 *
 * Tasarimin tek kritik ozelligi: bitis ani ISIN NE ZAMAN CALISTIGINA degil,
 * oturumun kendi verisine bagli. min(kapanis ani, baslangic + azami saat).
 *
 * Bu sayede is iki kez calisirsa ayni sonucu verir, cron 3 saat gec calisirsa
 * ayni sonucu verir, cron HIC calismazsa ilk bakan kisi dogru sonucu gorur.
 * Eger ended_at = now() olsaydi bunlarin ucu de yanlis sure uretirdi.
 */
class SessionCloserTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->withPackage(\App\Models\Package::factory()->tier3())->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    /** Yerel (kafe) saatiyle bir an. */
    private function yerel(string $zaman): Carbon
    {
        return Carbon::parse($zaman, config('kafe.timezone'));
    }

    private function oturum(User $ogrenci, Carbon $baslangic, ?StudyTable $masa = null): StudySession
    {
        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => ($masa ?? StudyTable::create(['name' => 'Masa 1']))->id,
            // utc() SART: Eloquent Carbon u cevirmez, duvar saatini yazar.
            'started_at' => $baslangic->copy()->utc(),
        ]);
    }

    private function kapatici(): SessionCloser
    {
        return app(SessionCloser::class);
    }

    public function test_a_forgotten_session_closes_at_the_cafe_closing_time(): void
    {
        $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->assertSame(1, $this->kapatici()->closeStale());
        Carbon::setTestNow();

        $oturum->refresh();
        $this->assertSame(
            $this->yerel('2026-09-21 21:00')->utc()->toIso8601String(),
            $oturum->ended_at->utc()->toIso8601String()
        );
        $this->assertSame(120, $oturum->duration_minutes);
        $this->assertSame(SessionEndReason::AutoClosed, $oturum->end_reason);
    }

    /** 12 saat siniri kapanistan once gelirse anomali; yonetici bakmali. */
    public function test_a_session_that_exceeds_the_limit_is_an_anomaly(): void
    {
        $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 08:00'));

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->kapatici()->closeStale();
        Carbon::setTestNow();

        $oturum->refresh();
        $this->assertSame(
            $this->yerel('2026-09-21 20:00')->utc()->toIso8601String(),
            $oturum->ended_at->utc()->toIso8601String()
        );
        $this->assertSame(720, $oturum->duration_minutes);
        $this->assertSame(SessionEndReason::OverLimit, $oturum->end_reason);
        $this->assertTrue($oturum->end_reason->isAnomaly());
    }

    /**
     * Tasarimin kalbi. Is 22:00'de de calissa, ertesi gun 09:00'da da calissa,
     * uc gun sonra da calissa bitis ani AYNI olmali.
     */
    public function test_the_end_time_does_not_depend_on_when_the_job_runs(): void
    {
        $sonuclar = [];

        // refreshDatabase() DONGU ICINDE cagrilmaz: testin ortasinda semayi
        // yeniden kurmak sonraki testleri de kirar. Her tur kendi ogrencisini
        // yaratir - kismi tekil indeks ogrenci basina calistigi icin yeterli.
        foreach (['2026-09-21 21:30', '2026-09-22 09:00', '2026-09-24 15:00'] as $calismaAni) {
            $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

            Carbon::setTestNow($this->yerel($calismaAni));
            $this->kapatici()->closeStale();
            Carbon::setTestNow();

            $sonuclar[$calismaAni] = [
                $oturum->refresh()->ended_at->toIso8601String(),
                $oturum->duration_minutes,
            ];
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $sonuclar)),
            'Bitis ani isin calisma anina gore degisiyor: ' . json_encode($sonuclar));
    }

    public function test_closing_twice_changes_nothing(): void
    {
        $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->kapatici()->closeStale();
        $ilk = $oturum->refresh()->only(['ended_at', 'duration_minutes', 'end_reason']);

        Carbon::setTestNow($this->yerel('2026-09-22 18:00'));
        $this->assertSame(0, $this->kapatici()->closeStale(), 'Kapali oturum tekrar kapatildi');
        Carbon::setTestNow();

        $this->assertEquals($ilk, $oturum->refresh()->only(['ended_at', 'duration_minutes', 'end_reason']));
    }

    public function test_an_ongoing_session_is_left_alone(): void
    {
        Carbon::setTestNow($this->yerel('2026-09-21 14:00'));
        $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 13:30'));

        $this->assertSame(0, $this->kapatici()->closeStale());
        Carbon::setTestNow();

        $this->assertNull($oturum->refresh()->ended_at);
    }

    /** Kafe kapaliyken okutma: bir sonraki kapanis 26 saat sonra, 12 saat siniri kazanir. */
    public function test_a_session_started_after_closing_falls_to_the_limit(): void
    {
        $oturum = $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 21:30'));

        Carbon::setTestNow($this->yerel('2026-09-23 10:00'));
        $this->kapatici()->closeStale();
        Carbon::setTestNow();

        $oturum->refresh();
        $this->assertSame(
            $this->yerel('2026-09-22 09:30')->utc()->toIso8601String(),
            $oturum->ended_at->utc()->toIso8601String()
        );
        $this->assertSame(SessionEndReason::OverLimit, $oturum->end_reason);
    }

    /**
     * Kismi tekil indeks, unutulmus bir oturum yuzunden ogrenciyi kilitlememeli.
     * Kapanmadan once yeni oturum acilamaz - bu yuzden tembel kapatma sart.
     */
    public function test_a_student_can_start_again_after_a_forgotten_session_is_closed(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, $this->yerel('2026-09-21 19:00'));

        $masa = StudyTable::create(['name' => 'Masa 2']);

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));
        Carbon::setTestNow();

        $this->assertSame(2, StudySession::count());
        $this->assertSame(1, StudySession::open()->count());
        $this->assertSame($masa->id, StudySession::open()->sole()->study_table_id);

        // Unutulmus oturum 'switched' ile DEGIL 'auto_closed' ile kapanmali:
        // ogrenci masa degistirmedi, dun cikis yapmayi unuttu. Tembel kapatma
        // olmasaydi start() onu masa degisimi sanip yanlis etiketlerdi.
        $eski = StudySession::whereNotNull('ended_at')->sole();
        $this->assertSame(SessionEndReason::AutoClosed, $eski->end_reason);
    }

    /** Tembel kapatma: cron hic calismasa da bakan kisi dogru veriyi gormeli. */
    public function test_the_live_screen_never_shows_a_stale_session(): void
    {
        $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->actingAs($yonetici)
            ->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Şu anda içeride kimse yok');
        Carbon::setTestNow();

        $this->assertSame(0, StudySession::open()->count());
    }

    public function test_the_console_command_closes_stale_sessions(): void
    {
        $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

        Carbon::setTestNow($this->yerel('2026-09-22 10:00'));
        $this->artisan('oturum:kapat')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(0, StudySession::open()->count());
    }
    /**
     * "12 saati asan oturum ... yoneticiye ANOMALI olarak duser" (FEATURE 1).
     * Sessizce kapatmak yetmez - kimse bakmazsa kural uygulanmamis demektir.
     */
    public function test_an_over_limit_closure_reaches_the_admin(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, $this->yerel('2026-09-21 08:00'));

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        Carbon::setTestNow($this->yerel('2026-09-21 23:00'));
        $this->actingAs($yonetici)
            ->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Süre aşımı')
            ->assertSee($ogrenci->name);
        Carbon::setTestNow();
    }

    /** Normal kapanis anomali degildir; her gun uyari gostermek uyariyi olduruyor. */
    public function test_a_normal_auto_close_is_not_shown_as_an_anomaly(): void
    {
        $this->oturum($this->ogrenci(), $this->yerel('2026-09-21 19:00'));

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        Carbon::setTestNow($this->yerel('2026-09-21 23:00'));
        $this->actingAs($yonetici)
            ->get(route('admin.live'))
            ->assertOk()
            ->assertDontSee('Süre aşımı');
        Carbon::setTestNow();
    }
    /**
     * Bir oturum IKI KEZ kapatilamaz.
     *
     * Senaryo: istek A acik oturumu belleğe okur; bu arada cron ya da baska bir
     * istegin middleware'i onu 'over_limit' ile kapatir; sonra A elindeki BAYAT
     * modelle kendi kapatmasini yazar. Sonuc: anomali SILINIR ve sure now()'a
     * gore sisirilir. Yazma "hala acikken" sartina baglanmali.
     */
    public function test_a_session_cannot_be_closed_twice(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci, $this->yerel('2026-09-21 08:00'));

        Carbon::setTestNow($this->yerel('2026-09-21 20:30'));

        // Istek A acik oturumu okudu.
        $bayatModel = app(StudySessionService::class)->openFor($ogrenci);

        // Bu arada baska biri kapatti: 08:00 + 12s = 20:00, sure asimi.
        $this->assertSame(1, $this->kapatici()->closeStale());

        // Istek A simdi elindeki bayat modelle kapatmaya calisiyor.
        app(StudySessionService::class)->close($bayatModel, SessionEndReason::Manual);

        Carbon::setTestNow();

        $son = $oturum->refresh();
        $this->assertSame(SessionEndReason::OverLimit, $son->end_reason, 'Anomali silindi');
        $this->assertSame(720, $son->duration_minutes, 'Sure sisirildi');
    }

    /** Ters yon: ogrenci elle bitirdiyse closeStale onun uzerine yazmamali. */
    public function test_auto_close_does_not_overwrite_a_manual_close(): void
    {
        $ogrenci = $this->ogrenci();
        $oturum = $this->oturum($ogrenci, $this->yerel('2026-09-21 08:00'));

        Carbon::setTestNow($this->yerel('2026-09-21 19:00'));

        // closeStale acik oturumlari okudu ama henuz yazmadi diye dusun:
        // ogrenci tam o anda elle bitirdi.
        app(StudySessionService::class)->endFor($ogrenci);

        $this->assertSame(0, $this->kapatici()->closeStale());
        Carbon::setTestNow();

        $this->assertSame(SessionEndReason::Manual, $oturum->refresh()->end_reason);
    }

    /**
     * "Yoneticiye anomali olarak duser" - ertesi gun bakarsa da duser.
     *
     * Pencere yalnizca "bugun" olsaydi hafta sonu ya da izin gunu dusen her
     * anomali hic gorulmeden kaybolurdu.
     */
    public function test_an_anomaly_is_still_visible_on_later_days(): void
    {
        $ogrenci = $this->ogrenci();
        $this->oturum($ogrenci, $this->yerel('2026-09-21 08:00'));

        Carbon::setTestNow($this->yerel('2026-09-21 22:00'));
        $this->kapatici()->closeStale();
        Carbon::setTestNow();

        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        foreach (['2026-09-21 23:00', '2026-09-22 09:00', '2026-09-24 10:00'] as $bakisAni) {
            Carbon::setTestNow($this->yerel($bakisAni));
            $this->actingAs($yonetici)
                ->get(route('admin.live'))
                ->assertOk()
                ->assertSee('Süre aşımı');
            Carbon::setTestNow();
        }
    }
}
