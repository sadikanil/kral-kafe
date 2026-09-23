<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Calisma sayaci erisilebilirligi (QA a11y A12).
 *
 * Hatirlatici gercek bir pencere degildi: odak icine gitmiyor, arka sayfa
 * dokunulabilir kaliyordu; VoiceOver kullanicisi yalnizca titresim
 * aliyordu. Telefon uykudan donunce gecmis her hatirlatici birer saniye
 * arayla titriyordu ve sayfa sunucuya hic bakmadigi icin 21:00'de kapanmis
 * oturum "Calisiyorsun" diye akmaya devam ediyordu.
 */
class TimerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $ogrenci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        $this->ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())->create();
        StudySession::create([
            'student_id' => $this->ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 1'])->id,
            'started_at' => now()->subMinutes(95),
        ]);
    }

    private function sayac(): string
    {
        return $this->actingAs($this->ogrenci)->get(route('session.timer'))->assertOk()->getContent();
    }

    public function test_the_reminder_is_a_labelled_modal_dialog(): void
    {
        $html = $this->sayac();

        $this->assertMatchesRegularExpression('/<dialog id="hatirlatici"[^>]*aria-labelledby="hatirlatici-baslik"[^>]*aria-describedby="hatirlatici-metin"/', $html);
        $this->assertMatchesRegularExpression('/<h3 id="hatirlatici-baslik"/', $html);
        $this->assertStringContainsString('showModal', $html);
        // Sayac betigi ortak yardimciyi kullaniyor: once tanimlanmis olmali.
        $this->assertLessThan(strpos($html, 'NetSaat.gosterilecek'), strpos($html, 'window.NetSaat ='));
    }

    /** Saat her saniye degil, dakika degisince yazilir; adi okunabilir. */
    public function test_the_clock_is_a_timer_with_a_spoken_label(): void
    {
        $this->assertMatchesRegularExpression(
            '/role="timer" aria-live="off" aria-label="Net çalışma 1 saat 35 dakika"/',
            $this->sayac(),
        );
    }

    /** Paneldeki kart artik donuk degil: ayni saat parcasi, ayni tik. */
    public function test_the_panel_card_clock_ticks_with_the_same_script(): void
    {
        $html = $this->actingAs($this->ogrenci)->get(route('user.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-net-saat="5700"[^>]*data-akiyor="1"/', $html);
        $this->assertMatchesRegularExpression('/role="timer" aria-live="off" aria-label="Net çalışma 1 saat 35 dakika"/', $html);
        $this->assertStringContainsString('// net-saat:bas', $html);
    }

    /** @return array<string,array{0:string,1:mixed}> JS ifadesi, beklenen */
    public static function safIslevler(): array
    {
        $liste = '[{at:1800},{at:3600},{at:5400}]';

        return [
            'saat metni' => ['saatMetni(5700)', '01:35'],
            'etiket saatli' => ['saatEtiketi(5700)', 'Net çalışma 1 saat 35 dakika'],
            'etiket saatsiz' => ['saatEtiketi(300)', 'Net çalışma 5 dakika'],
            'uykudan donus: yalniz sonuncusu' => ["gosterilecek({$liste}, 0, 5500)", 2],
            'henuz zamani yok' => ["gosterilecek({$liste}, 0, 100)", -1],
            'hepsi gosterildi' => ["gosterilecek({$liste}, 3, 99999)", -1],
            'tam zamaninda' => ["gosterilecek({$liste}, 1, 3600)", 1],
            'bir dakikadan uzun gizli: yenile' => ['yenilensinMi(61000, false)', true],
            'kisa gizli: yenileme' => ['yenilensinMi(59000, false)', false],
            'yazi yaziliyor: yenileme' => ['yenilensinMi(600000, true)', false],
        ];
    }

    #[DataProvider('safIslevler')]
    public function test_the_clock_helpers(string $ifade, mixed $beklenen): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node yok: sayac yardimcilari JS, node ile sinaniyor.');
        }

        preg_match('~// net-saat:bas(.*?)// net-saat:son~s', $this->sayac(), $eslesme);
        $this->assertNotEmpty($eslesme, 'net-saat yardimcilari sayfada bulunamadi');

        $surec = new Process([$node, '-e', $eslesme[1] . "\nprocess.stdout.write(JSON.stringify({$ifade}));"]);
        $surec->mustRun();

        $this->assertSame($beklenen, json_decode($surec->getOutput(), true));
    }
}
