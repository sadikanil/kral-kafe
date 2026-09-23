<?php

namespace Tests\Feature;

use App\Enums\ExamType;
use App\Models\ExamEvent;
use App\Models\ExamReport;
use App\Models\ExamResult;
use App\Models\Package;
use App\Models\StudentParent;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faz 2 / Grup E: deneme ekranlarinin QA bulgularina ek regresyonlar.
 * Smoke testlerdeki ana senaryolarin yaninda sinir durumlari tutar:
 * veli gorunumu, ay sinirlari, yetki-once-404, etiketler, sira/katilimci.
 *
 * Saat: 29 Eylul 2026 Sali 14:00 (kafe saati).
 */
class ExamHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));

        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => 'test-anahtari']);
        Storage::fake('yukleme');
        Http::preventStrayRequests();
    }

    private function ogrenci(?\Database\Factories\PackageFactory $paket = null, array $ek = []): User
    {
        return User::factory()->student()->withPackage($paket ?? Package::factory()->tier3())
            ->create(array_merge(['grade' => '12', 'field' => 'say'], $ek));
    }

    private function deneme(string $tarih, string $ad = 'Kurum Denemesi', array $ek = []): ExamEvent
    {
        return ExamEvent::create(array_merge(['title' => $ad, 'exam_type' => 'tyt', 'exam_date' => $tarih], $ek));
    }

    private function tytGirdisi(User $ogrenci, ExamEvent $deneme): array
    {
        return Subject::forExam($deneme->exam_type, $ogrenci)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['correct' => 20, 'wrong' => 8, 'blank' => 0]])
            ->all();
    }

    // --- Bug 16: serbest deneme adi yalnizca deneme kulubunde ------------------

    public function test_a_parent_without_the_exam_club_sees_the_flexible_window_but_not_the_name(): void
    {
        $cocuk = $this->ogrenci(Package::factory()->tier1());
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $cocuk->id, 'parent_id' => $veli->id]);
        $this->deneme('2026-09-01', 'Gizli Serbest Deneme', [
            'is_flexible' => true, 'available_until' => '2026-10-15', 'note' => 'Gizli serbest not',
        ]);

        $this->actingAs($veli)->get(route('parent.exams'))
            ->assertOk()
            ->assertSee('Serbest denemeler')
            ->assertSee('1 Eylül – 15 Ekim')
            ->assertDontSee('Gizli Serbest Deneme')
            ->assertDontSee('Gizli serbest not');
    }

    public function test_club_members_and_the_admin_still_see_the_flexible_name_and_note(): void
    {
        $this->deneme('2026-09-01', 'Açık Serbest Deneme', [
            'is_flexible' => true, 'available_until' => '2026-10-15', 'note' => 'Optik form getir',
        ]);

        $this->actingAs($this->ogrenci())->get(route('user.exams'))
            ->assertOk()->assertSee('Açık Serbest Deneme')->assertSee('Optik form getir');

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.exams.index'))
            ->assertOk()->assertSee('Açık Serbest Deneme')->assertSee('Optik form getir');
    }

    // --- Bug 19: ayin ilk ve son gunu izgarada, komsu aylar disarida ----------

    public function test_in_month_takes_the_first_and_last_day_and_nothing_outside(): void
    {
        $this->deneme('2026-07-31', 'Temmuz Sonu');
        $ilk = $this->deneme('2026-08-01', 'Ağustos Başı');
        $son = $this->deneme('2026-08-31', 'Ağustos Sonu');
        $this->deneme('2026-09-01', 'Eylül Başı');

        $this->assertSame([$ilk->id, $son->id], ExamEvent::inMonth(2026, 8)->pluck('id')->all());
    }

    public function test_in_month_crosses_the_year_end(): void
    {
        $aralik = $this->deneme('2026-12-31', 'Yıl Sonu');
        $this->deneme('2027-01-01', 'Yılbaşı');

        $this->assertSame([$aralik->id], ExamEvent::inMonth(2026, 12)->pluck('id')->all());
    }

    public function test_the_admin_grid_shows_an_exam_on_the_last_day_of_the_month(): void
    {
        $this->deneme('2026-08-31', 'Ağustos Son Gün Denemesi');

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.exams.index', ['ay' => '2026-08']))
            ->assertOk()->getContent();

        // "Gecmis denemeler" listesi de adi gosterir; yalnizca izgaraya bak
        $izgara = \Illuminate\Support\Str::betweenFirst($html, 'style="table-layout: fixed;"', '</table>');
        $this->assertStringContainsString('Ağustos Son Gün Denemesi', $izgara);
    }

    // --- Bug 17/46: dosyasi kaybolmus rapor -----------------------------------

    public function test_a_stranger_gets_forbidden_even_when_the_file_is_missing(): void
    {
        // Yetki kontrolu dosya kontrolunden once: 404/403 farki, baskasinin
        // raporunun varligini ele vermesin.
        $rapor = ExamReport::factory()->analyzed()->create();

        $this->actingAs($this->ogrenci())->get(route('user.exam-reports.pdf', $rapor))->assertForbidden();
    }

    // --- Bug 45: basarisiz yeniden analiz onceki iyi analizi gizlemez ---------

    public function test_a_failed_reanalysis_tells_the_admin_and_leaves_the_old_analysis_untouched(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('patladı', 503)]);
        $rapor = ExamReport::factory()->analyzed()->create();
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF-1.4');
        $once = $rapor->fresh();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.exam-reports.index', $rapor->student_id))
            ->post(route('admin.exam-reports.analyze', $rapor))
            ->assertRedirect(route('admin.exam-reports.index', $rapor->student_id))
            ->assertSessionHas('error', fn ($mesaj) => str_contains($mesaj, 'önceki analiz yerinde duruyor') && str_contains($mesaj, '503'));

        $sonra = $rapor->fresh();
        $this->assertSame(ExamReport::DONE, $sonra->status);
        $this->assertSame($once->analysis, $sonra->analysis);
        $this->assertNull($sonra->error);
        $this->assertEquals($once->analyzed_at, $sonra->analyzed_at);
    }

    // --- Bug 49: hata mesajlarinda Turkce alan adlari -------------------------

    public function test_result_errors_name_the_ranking_and_the_subject_box(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $turkce = Subject::where('code', 'tyt_turkce')->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => '0',
                'subjects' => [$turkce->id => ['correct' => 201, 'wrong' => 0, 'blank' => 'x']],
            ])
            ->assertSessionHasErrors([
                'rank_institution' => 'kurum sırası en az 1 olmalıdır.',
                "subjects.{$turkce->id}.correct" => 'TYT Türkçe doğru sayısı 200 değerinden büyük olmamalıdır.',
                "subjects.{$turkce->id}.blank" => 'TYT Türkçe boş sayısı bir tam sayı olmalıdır.',
            ]);
    }

    public function test_a_flexible_window_error_names_the_last_day(): void
    {
        $this->actingAs(User::factory()->admin()->create())->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
                'is_flexible' => '1', 'available_until' => '2026-10-01',
            ])
            ->assertSessionHasErrors(['available_until' => 'Serbest denemenin son günü, ilk günden (Tarih) önce olamaz.']);

        // Diger kurallar da ham anahtari degil Turkce adi kullanir
        $this->actingAs(User::factory()->admin()->create())->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
                'is_flexible' => '1', 'available_until' => '30.11.2026',
            ])
            ->assertSessionHasErrors(['available_until' => 'serbest denemenin son günü Y-m-d biçimiyle eşleşmiyor.']);
    }

    // --- Bug 50 / A3 / A19: sonuc formunun etiketleri ve klavyesi -------------

    public function test_every_control_on_the_result_form_has_a_label(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))->assertOk()->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xp = new \DOMXPath($dom);
        $form = $xp->query('//form[contains(@action, "/sonuc")]')->item(0);
        $this->assertNotNull($form);

        $kontroller = $xp->query('.//input[not(@type="hidden")] | .//textarea | .//select', $form);
        $this->assertGreaterThan(10, $kontroller->length);
        foreach ($kontroller as $kontrol) {
            $id = $kontrol->getAttribute('id');
            $etiketli = ($id !== '' && $xp->query('//label[@for="' . $id . '"]')->length === 1)
                || $xp->query('ancestor::label', $kontrol)->length > 0;
            $this->assertTrue($etiketli, "'{$kontrol->getAttribute('name')}' kutusunun etiketi yok");
        }

        // Her ders kendi grubunda: ekran okuyucu "TYT Türkçe, Doğru" okur
        $this->assertGreaterThan(0, $xp->query('//fieldset/legend[contains(., "TYT Türkçe")]')->length);
    }

    public function test_count_and_rank_boxes_open_the_numeric_keypad(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))->assertOk()->getContent();

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $kutular);
        $this->assertNotEmpty($kutular[0]);
        foreach ($kutular[0] as $kutu) {
            $this->assertStringContainsString('inputmode="numeric"', $kutu);
        }
    }

    // --- Bug 51: sira katilimci sayisini gecemez ------------------------------

    public function test_rank_and_participant_count_combinations(): void
    {
        $yonetici = User::factory()->admin()->create();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $gonder = fn (array $sira) => $this->actingAs($yonetici)
            ->from(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), $sira + ['subjects' => $this->tytGirdisi($ogrenci, $deneme)]);

        $gonder(['rank_country' => '1240', 'total_country' => '87'])
            ->assertSessionHasErrors(['rank_country' => 'Türkiye sırası, katılımcı sayısından büyük olamaz.']);

        // Sira tek basina (katilimci sonra aciklanir), katilimci tek basina, esit
        $gonder(['rank_institution' => '87'])->assertSessionHasNoErrors();
        $gonder(['total_institution' => '87'])->assertSessionHasNoErrors();
        $gonder(['rank_institution' => '87', 'total_institution' => '87'])->assertSessionHasNoErrors();

        $this->assertSame(87, ExamResult::sole()->rank_institution);
    }

    // --- Bug 52: bu denemede olmayan ders ------------------------------------

    public function test_a_subject_outside_the_exam_is_refused(): void
    {
        $yonetici = User::factory()->admin()->create();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $formdan = route('admin.exam-results.edit', [$deneme, $ogrenci]);
        $aytMat = Subject::where('code', 'ayt_matematik')->firstOrFail();

        // TYT denemesine AYT dersi: net haksiz yere sismesin
        $this->actingAs($yonetici)->from($formdan)
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => $this->tytGirdisi($ogrenci, $deneme) + [$aytMat->id => ['correct' => 40, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertRedirect($formdan)
            ->assertSessionHasErrors('subjects');

        // Sayi olmayan anahtar da 500 degil, dogrulama hatasi
        $this->actingAs($yonetici)->from($formdan)
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => ['abc' => ['correct' => 1, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertRedirect($formdan)
            ->assertSessionHasErrors('subjects');

        $this->assertSame(0, ExamResult::count());
    }
}
