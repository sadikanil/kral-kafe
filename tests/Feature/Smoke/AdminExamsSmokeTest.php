<?php

namespace Tests\Feature\Smoke;

use App\Enums\ExamType;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\ExamReport;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\Package;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Smoke/QA: yoneticinin deneme ekranlari uctan uca.
 *
 *  - Deneme takvimi (Admin\ExamEventController): liste, ay gezinmesi,
 *    ekle/duzenle/kaldir, serbest pencere (is_flexible, available_until).
 *  - Deneme sonucu (Admin\ExamResultController): ogrenci basina ders
 *    bazli giris, siralamalar, tekrar kaydetme.
 *  - Deneme raporlari (Admin\ExamReportController): PDF yukle, analiz,
 *    yeniden analiz, PDF indir, kaldir.
 *
 * Saat: 29 Eylul 2026 Sali 14:00 (kafe saati).
 */
class AdminExamsSmokeTest extends TestCase
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

    // --- Yardimcilar ---------------------------------------------------------

    private function yonetici(): User
    {
        return User::factory()->admin()->create(['name' => 'Yönetici Ayşe']);
    }

    private function ogrenci(array $ek = []): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier3())->create(array_merge([
            'name' => 'Çağrı Işıkoğlu',
            'grade' => '12',
            'field' => 'say',
        ], $ek));
    }

    private function koc(): User
    {
        return User::factory()->create(['role' => Role::Coach->value, 'subscription_status' => 'active']);
    }

    private function deneme(string $tarih = '2026-10-04', array $ek = []): ExamEvent
    {
        return ExamEvent::factory()->create(array_merge([
            'title' => 'Türkiye Geneli TYT 3',
            'exam_type' => ExamType::Tyt->value,
            'exam_date' => $tarih,
            'starts_at' => '10:00',
        ], $ek));
    }

    private function serbest(string $bas = '2026-10-01', string $son = '2026-10-31', array $ek = []): ExamEvent
    {
        return ExamEvent::create(array_merge([
            'title' => 'Hız ve Renk Serbest',
            'exam_type' => 'tyt',
            'exam_date' => $bas,
            'is_flexible' => true,
            'available_until' => $son,
        ], $ek));
    }

    private function ders(string $kod): Subject
    {
        return Subject::where('code', $kod)->firstOrFail();
    }

    private function pdf(string $ad = 'sonuc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($ad, "%PDF-1.4\n% sahte\n");
    }

    private function analiz(array $ek = []): array
    {
        return array_merge([
            'exam' => ['name' => 'TG TYT 3', 'type' => 'TYT', 'date' => '2026-09-27'],
            'overall' => ['correct' => 90, 'wrong' => 20, 'blank' => 10, 'net' => 85, 'score' => 412.5, 'rank' => '1.250'],
            'subjects' => [['name' => 'TYT Matematik', 'correct' => 20, 'wrong' => 10, 'blank' => 10, 'net' => 17.5]],
            'strong_areas' => [['subject' => 'TYT Türkçe', 'topic' => 'Paragraf', 'evidence' => '18/18 doğru']],
            'weak_areas' => [['subject' => 'TYT Matematik', 'topic' => 'Problemler', 'evidence' => '2/12 doğru']],
            'focus_suggestions' => ['Problemler konusunu tekrar et.'],
            'summary' => 'Türkçe netleri yüksek, matematik problemleri düşük.',
        ], $ek);
    }

    private function apiDonsun(array $icerik): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($icerik)]]]])]);
    }

    /** Tum TYT dersleri icin gecerli bir sonuc girdisi. */
    private function tytGirdisi(User $ogrenci, ExamEvent $deneme, int $dogru = 20, int $yanlis = 8): array
    {
        return Subject::forExam($deneme->exam_type, $ogrenci)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['correct' => $dogru, 'wrong' => $yanlis, 'blank' => 0]])
            ->all();
    }

    // === Deneme takvimi (ExamEventController) =================================

    public function test_admin_sees_the_calendar_with_upcoming_flexible_and_past_exams(): void
    {
        $yonetici = $this->yonetici();
        $this->deneme('2026-10-04', ['title' => 'Türkiye Geneli TYT 3', 'note' => 'Optik form getirin; salon: üst kat']);
        $this->deneme('2026-09-30', ['title' => 'Kurum AYT Şölen', 'exam_type' => 'ayt', 'starts_at' => '09:30']);
        $this->serbest();
        $this->deneme('2026-09-20', ['title' => 'Eylül Geçmiş Deneme']);

        $this->actingAs($yonetici)->get(route('admin.exams.index'))
            ->assertOk()
            ->assertSee('Deneme Takvimi')
            ->assertSee('Eylül 2026')
            // Yaklasanlar: yakindan uzaga, geri sayim kafe gunune gore
            ->assertSeeInOrder(['Kurum AYT Şölen', 'Türkiye Geneli TYT 3'])
            ->assertSee('Yarın')
            ->assertSee('5 gün kaldı')
            ->assertSee('Optik form getirin; salon: üst kat')
            // Serbest pencere
            ->assertSee('Serbest denemeler')
            ->assertSee('Hız ve Renk Serbest')
            ->assertSee('1–31 Ekim')
            // Gecmis liste
            ->assertSee('Geçmiş denemeler')
            ->assertSee('Eylül Geçmiş Deneme')
            ->assertSee('20.09.2026')
            // Baglantilar
            ->assertSee(route('admin.exams.create'))
            ->assertSee(route('admin.exams.index', ['ay' => '2026-10']))
            ->assertSee(route('admin.exams.index', ['ay' => '2026-08']));
    }

    public function test_the_empty_calendar_and_month_navigation(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.exams.index'))
            ->assertOk()
            ->assertSee('Planlanmış deneme yok')
            ->assertDontSee('Geçmiş denemeler')
            ->assertDontSee('Serbest denemeler');

        $this->deneme('2026-11-14', ['title' => 'Kasım Denemesi']);

        $this->actingAs($yonetici)->get(route('admin.exams.index', ['ay' => '2026-11']))
            ->assertOk()
            ->assertSee('Kasım 2026')
            ->assertSee(route('admin.exams.index', ['ay' => '2026-12']))
            ->assertSee(route('admin.exams.edit', ExamEvent::sole()));

        // Bozuk ay degeri icinde bulunulan aya duser
        $this->actingAs($yonetici)->get(route('admin.exams.index', ['ay' => '2026-13']))
            ->assertOk()->assertSee('Eylül 2026');
    }

    public function test_an_array_month_parameter_does_not_crash_the_calendar(): void
    {
        $this->markTestSkipped('BUG: ?ay[]= query string crashes the exam calendar with a TypeError (500)');

        $this->actingAs($this->yonetici())->get('/yonetim/denemeler?ay[]=2026-10')
            ->assertOk()
            ->assertSee('Eylül 2026');
    }

    public function test_just_after_local_midnight_todays_exam_is_upcoming_not_past(): void
    {
        // Istanbul 30 Eylul 00:30 = UTC 29 Eylul 21:30
        $this->travelTo(Carbon::parse('2026-09-30 00:30', config('kafe.timezone')));
        $this->deneme('2026-09-30', ['title' => 'Gece Yarısı Denemesi']);

        $this->actingAs($this->yonetici())->get(route('admin.exams.index'))
            ->assertOk()
            ->assertSee('Gece Yarısı Denemesi')
            ->assertSee('Bugün')
            ->assertDontSee('Geçmiş denemeler');
    }

    public function test_non_admins_are_refused_on_every_exam_calendar_route(): void
    {
        $deneme = $this->deneme();
        $veri = ['title' => 'X', 'exam_type' => 'tyt', 'exam_date' => '2026-10-10'];

        // Misafir ONCE: actingAs oturumu test boyunca acik birakir.
        $this->get(route('admin.exams.index'))->assertRedirect(route('login'));
        $this->post(route('admin.exams.store'), $veri)->assertRedirect(route('login'));

        foreach ([$this->ogrenci(), User::factory()->parent()->create(), $this->koc()] as $kullanici) {
            $this->actingAs($kullanici)->get(route('admin.exams.index'))->assertForbidden();
            $this->actingAs($kullanici)->get(route('admin.exams.create'))->assertForbidden();
            $this->actingAs($kullanici)->get(route('admin.exams.edit', $deneme))->assertForbidden();
            $this->actingAs($kullanici)->post(route('admin.exams.store'), $veri)->assertForbidden();
            $this->actingAs($kullanici)->put(route('admin.exams.update', $deneme), $veri)->assertForbidden();
            $this->actingAs($kullanici)->delete(route('admin.exams.destroy', $deneme))->assertForbidden();
        }

        $this->assertSame(1, ExamEvent::count());
        $this->assertSame('Türkiye Geneli TYT 3', $deneme->fresh()->title);
    }

    public function test_the_create_page_renders_the_form(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.exams.create'))
            ->assertOk()
            ->assertSee('Yeni Deneme')
            ->assertSee('action="' . route('admin.exams.store') . '"', false)
            ->assertSee('name="is_flexible"', false)
            ->assertSee('name="available_until"', false)
            ->assertSee('Resmî Sınav')
            ->assertSee(route('admin.exams.index'));
    }

    public function test_admin_creates_an_exam_with_turkish_title_and_note(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->post(route('admin.exams.store'), [
            'title' => 'Özdebir Türkiye Geneli — Işık & Çağ (İzmir)',
            'exam_type' => 'tyt_ayt',
            'exam_date' => '2026-10-11',
            'starts_at' => '09:30',
            'note' => 'Optik form ve kurşun kalem getirin; salon: üst kat',
            'is_flexible' => '0',
            'available_until' => '',
        ])
            ->assertRedirect(route('admin.exams.index'))
            ->assertSessionHas('success', 'Deneme takvime eklendi.');

        $deneme = ExamEvent::sole();
        $this->assertSame('Özdebir Türkiye Geneli — Işık & Çağ (İzmir)', $deneme->title);
        $this->assertSame(ExamType::TytAyt, $deneme->exam_type);
        $this->assertSame('2026-10-11', $deneme->exam_date->toDateString());
        $this->assertSame('09:30', $deneme->starts_at);
        $this->assertFalse($deneme->is_flexible);
        $this->assertNull($deneme->available_until);
        $this->assertSame($yonetici->id, $deneme->created_by);

        $this->actingAs($yonetici)->get(route('admin.exams.index'))
            ->assertOk()
            ->assertSee('Özdebir Türkiye Geneli — Işık &amp; Çağ (İzmir)', false)
            ->assertSee('11 Ekim Pazar')
            ->assertSee('12 gün kaldı');
    }

    public function test_the_exam_form_rejects_missing_or_bad_values(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => '',
                'exam_type' => 'kpss',
                'exam_date' => '11.10.2026',
                'starts_at' => '25:00',
            ])
            ->assertRedirect(route('admin.exams.create'))
            ->assertSessionHasErrors(['title', 'exam_type', 'exam_date', 'starts_at']);

        $this->actingAs($yonetici)->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => str_repeat('ş', 101),
                'exam_type' => 'tyt',
                'exam_date' => '2026-10-11',
            ])
            ->assertSessionHasErrors('title');

        // Hatali gonderimde form girilenlerle geri gelir
        $this->actingAs($yonetici)->from(route('admin.exams.create'))->followingRedirects()
            ->post(route('admin.exams.store'), [
                'title' => 'Şişli Işık Denemesi', 'exam_type' => 'kpss', 'exam_date' => '2026-10-11', 'note' => 'Üst kat',
            ])
            ->assertOk()
            ->assertSee('Hata!')
            ->assertSee('value="Şişli Işık Denemesi"', false)
            ->assertSee('value="2026-10-11"', false)
            ->assertSee('value="Üst kat"', false);

        $this->assertSame(0, ExamEvent::count());
    }

    public function test_admin_creates_a_flexible_exam_and_it_is_listed_with_its_window(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->post(route('admin.exams.store'), [
            'title' => 'Hız ve Renk Kasım',
            'exam_type' => 'ayt',
            'exam_date' => '2026-11-01',
            'is_flexible' => '1',
            'available_until' => '2026-11-30',
        ])->assertRedirect(route('admin.exams.index'));

        $deneme = ExamEvent::sole();
        $this->assertTrue($deneme->is_flexible);
        $this->assertSame('2026-11-30', $deneme->available_until->toDateString());

        // Serbest deneme yaklasanlar listesinde ve takvim gununde gorunmez
        $this->actingAs($yonetici)->get(route('admin.exams.index', ['ay' => '2026-11']))
            ->assertOk()
            ->assertSee('Serbest denemeler')
            ->assertSee('Hız ve Renk Kasım')
            ->assertSee('1–30 Kasım')
            ->assertSee('Planlanmış deneme yok')
            ->assertSee(route('admin.exams.edit', $deneme));
    }

    public function test_a_flexible_window_needs_a_last_day_on_or_after_the_first(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01', 'is_flexible' => '1',
            ])
            ->assertRedirect(route('admin.exams.create'))
            ->assertSessionHasErrors(['available_until' => 'Serbest denemenin son gününü seçin.']);

        $this->actingAs($yonetici)->from(route('admin.exams.create'))
            ->post(route('admin.exams.store'), [
                'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
                'is_flexible' => '1', 'available_until' => '2026-10-31',
            ])
            ->assertSessionHasErrors('available_until');

        // Tek gunluk pencere gecerli
        $this->actingAs($yonetici)->post(route('admin.exams.store'), [
            'title' => 'Tek Gün', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
            'is_flexible' => '1', 'available_until' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Tek Gün'], ExamEvent::pluck('title')->all());
    }

    public function test_a_fixed_exam_drops_a_filled_last_day(): void
    {
        $this->actingAs($this->yonetici())->post(route('admin.exams.store'), [
            'title' => 'Sabit Deneme', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
            'is_flexible' => '0', 'available_until' => '2026-11-20',
        ])->assertRedirect(route('admin.exams.index'));

        $deneme = ExamEvent::sole();
        $this->assertFalse($deneme->is_flexible);
        $this->assertNull($deneme->available_until);
    }

    public function test_the_edit_page_is_prefilled(): void
    {
        $serbest = $this->serbest('2026-10-01', '2026-10-31', ['note' => 'Kulüp üyelerine']);

        $this->actingAs($this->yonetici())->get(route('admin.exams.edit', $serbest))
            ->assertOk()
            ->assertSee('Deneme: Hız ve Renk Serbest')
            ->assertSee('action="' . route('admin.exams.update', $serbest) . '"', false)
            ->assertSee('value="2026-10-01"', false)
            ->assertSee('value="2026-10-31"', false)
            ->assertSee('value="Kulüp üyelerine"', false)
            ->assertSee('checked', false);
    }

    public function test_admin_updates_an_exam(): void
    {
        $yonetici = $this->yonetici();
        $deneme = $this->deneme('2026-10-04');

        $this->actingAs($yonetici)->put(route('admin.exams.update', $deneme), [
            'title' => 'Türkiye Geneli TYT 3 (ertelendi)',
            'exam_type' => 'ayt',
            'exam_date' => '2026-10-18',
            'starts_at' => '',
            'note' => 'Salon değişti: alt kat',
            'is_flexible' => '0',
            'available_until' => '',
        ])
            ->assertRedirect(route('admin.exams.index'))
            ->assertSessionHas('success', 'Deneme güncellendi.');

        $deneme->refresh();
        $this->assertSame('Türkiye Geneli TYT 3 (ertelendi)', $deneme->title);
        $this->assertSame(ExamType::Ayt, $deneme->exam_type);
        $this->assertSame('2026-10-18', $deneme->exam_date->toDateString());
        $this->assertNull($deneme->starts_at);
        $this->assertSame('Salon değişti: alt kat', $deneme->note);
        // Guncellemeyi yapan olusturan yerine gecmez
        $this->assertNull($deneme->created_by);

        // Gecersiz guncelleme kaydi bozmaz
        $this->actingAs($yonetici)->from(route('admin.exams.edit', $deneme))
            ->put(route('admin.exams.update', $deneme), ['title' => '', 'exam_type' => 'ayt', 'exam_date' => '2026-10-18'])
            ->assertRedirect(route('admin.exams.edit', $deneme))
            ->assertSessionHasErrors('title');
        $this->assertSame('Türkiye Geneli TYT 3 (ertelendi)', $deneme->fresh()->title);
    }

    public function test_turning_a_flexible_exam_into_a_fixed_one_clears_the_window(): void
    {
        $serbest = $this->serbest('2026-10-01', '2026-10-31');

        $this->actingAs($this->yonetici())->put(route('admin.exams.update', $serbest), [
            'title' => 'Hız ve Renk Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-10-10',
            'is_flexible' => '0', 'available_until' => '2026-10-31',
        ])->assertRedirect(route('admin.exams.index'));

        $serbest->refresh();
        $this->assertFalse($serbest->is_flexible);
        $this->assertNull($serbest->available_until);
    }

    public function test_unticking_flexible_with_a_stale_last_day_still_saves(): void
    {
        $this->markTestSkipped('BUG: available_until is validated even when the exam is not flexible');

        // Duzenleme formu eski pencerenin son gununu dolu getirir; yonetici
        // "Serbest" kutusunu kaldirip tarihi pencereden sonraya alir.
        $serbest = $this->serbest('2026-10-01', '2026-10-31');

        $this->actingAs($this->yonetici())->from(route('admin.exams.edit', $serbest))
            ->put(route('admin.exams.update', $serbest), [
                'title' => 'Hız ve Renk Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-07',
                'is_flexible' => '0', 'available_until' => '2026-10-31',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.exams.index'));

        $serbest->refresh();
        $this->assertFalse($serbest->is_flexible);
        $this->assertSame('2026-11-07', $serbest->exam_date->toDateString());
        $this->assertNull($serbest->available_until);
    }

    public function test_admin_removes_an_exam_its_results_go_and_reports_are_unlinked(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
            'subjects' => $this->tytGirdisi($ogrenci, $deneme),
        ])->assertRedirect();
        $rapor = ExamReport::factory()->analyzed()->create(['student_id' => $ogrenci->id, 'exam_event_id' => $deneme->id]);

        $this->actingAs($yonetici)->delete(route('admin.exams.destroy', $deneme))
            ->assertRedirect(route('admin.exams.index'))
            ->assertSessionHas('success', 'Deneme takvimden kaldırıldı.');

        $this->assertSame(0, ExamEvent::count());
        $this->assertSame(0, ExamResult::count());
        $this->assertSame(0, ExamResultSubject::count());
        $this->assertNull($rapor->fresh()->exam_event_id);

        // Denemesi silinmis rapor sayfayi bozmaz
        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $ogrenci))
            ->assertOk()->assertSee($rapor->title);

        // Ikinci kez silmek (cift tiklama) 404
        $this->actingAs($yonetici)->delete(route('admin.exams.destroy', $deneme))->assertNotFound();
    }

    public function test_every_kind_of_exam_can_be_removed_from_the_admin_screens(): void
    {
        $this->markTestSkipped('BUG: flexible and official exams have no remove button anywhere in the admin UI');

        $yonetici = $this->yonetici();
        $resmi = $this->deneme('2026-10-20', ['title' => 'YKS Provası', 'exam_type' => ExamType::Official->value]);
        $serbest = $this->serbest();

        foreach ([$resmi, $serbest] as $deneme) {
            $sayfalar = $this->actingAs($yonetici)->get(route('admin.exams.index', ['ay' => '2026-10']))->assertOk()->getContent()
                . $this->actingAs($yonetici)->get(route('admin.exams.edit', $deneme))->assertOk()->getContent();

            // destroy ve update ayni URL: DELETE yontemli formu ara
            $this->assertMatchesRegularExpression(
                $this->silmeFormu(route('admin.exams.destroy', $deneme)),
                $sayfalar,
                "'{$deneme->title}' icin kaldirma formu yok"
            );
        }
    }

    public function test_the_calendar_offers_a_remove_form_for_an_upcoming_exam(): void
    {
        $deneme = $this->deneme();

        $sayfa = $this->actingAs($this->yonetici())->get(route('admin.exams.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression($this->silmeFormu(route('admin.exams.destroy', $deneme)), $sayfa);
    }

    /** Verilen adrese DELETE gonderen bir <form> arar. */
    private function silmeFormu(string $url): string
    {
        return '#<form[^>]*action="' . preg_quote($url, '#') . '"[^>]*>(?:(?!</form>).)*name="_method" value="DELETE"#s';
    }

    public function test_validation_messages_name_the_fields_in_turkish(): void
    {
        $this->markTestSkipped('BUG: exam form errors show raw field keys ("available until", "rank institution", "subjects.1.correct")');

        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $turkce = $this->ders('tyt_turkce');

        $sonucSayfasi = $this->actingAs($yonetici)->from(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->followingRedirects()
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => '0',
                'subjects' => [$turkce->id => ['correct' => 201, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertOk()
            ->assertSee('Hata!');

        $takvimSayfasi = $this->actingAs($yonetici)->from(route('admin.exams.create'))
            ->followingRedirects()
            ->post(route('admin.exams.store'), [
                'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-11-01',
                'is_flexible' => '1', 'available_until' => '2026-10-01',
            ])
            ->assertOk()
            ->assertSee('Hata!');

        $hatalar = fn ($yanit) => preg_match('#<ul class="mb-0 mt-1">(.*?)</ul>#s', $yanit->getContent(), $m) ? strip_tags($m[1]) : '';
        $sonucHatalari = $hatalar($sonucSayfasi);
        $takvimHatalari = $hatalar($takvimSayfasi);

        $this->assertStringNotContainsString('rank institution', $sonucHatalari);
        $this->assertStringNotContainsString('subjects.', $sonucHatalari);
        $this->assertStringNotContainsString('available until', $takvimHatalari);
        // Hangi dersin hatali oldugu anlasilmali
        $this->assertStringContainsString('Türkçe', $sonucHatalari);
    }

    public function test_editing_or_removing_a_missing_exam_is_a_404(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get('/yonetim/denemeler/999999/edit')->assertNotFound();
        $this->actingAs($yonetici)->put('/yonetim/denemeler/999999', ['title' => 'X', 'exam_type' => 'tyt', 'exam_date' => '2026-10-10'])->assertNotFound();
        $this->actingAs($yonetici)->delete('/yonetim/denemeler/999999')->assertNotFound();
    }

    // === Deneme sonucu (ExamResultController) =================================

    public function test_the_result_form_lists_the_subjects_of_the_exam_type_and_field(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci(['field' => 'say']);
        $tyt = $this->deneme('2026-09-27', ['title' => 'TG TYT 3']);
        $ayt = $this->deneme('2026-09-28', ['title' => 'TG AYT 3', 'exam_type' => 'ayt']);

        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$tyt, $ogrenci]))
            ->assertOk()
            ->assertSee('TG TYT 3')
            ->assertSee('Çağrı Işıkoğlu')
            ->assertSee('27.09.2026')
            ->assertSee('TYT Türkçe')
            ->assertSee('TYT Matematik')
            ->assertDontSee('AYT Fizik')
            ->assertDontSee('Edebiyat')
            ->assertSee('name="subjects[' . $this->ders('tyt_turkce')->id . '][correct]"', false)
            ->assertSee('action="' . route('admin.exam-results.store', [$tyt, $ogrenci]) . '"', false);

        // Sayisalci AYT'de Edebiyat netini girmez
        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$ayt, $ogrenci]))
            ->assertOk()
            ->assertSee('AYT Matematik')
            ->assertSee('AYT Fizik')
            ->assertDontSee('Edebiyat')
            ->assertDontSee('TYT Türkçe');
    }

    public function test_admin_saves_a_result_and_the_form_comes_back_prefilled(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $turkce = $this->ders('tyt_turkce');
        $girdi = $this->tytGirdisi($ogrenci, $deneme, 0, 0);
        $girdi[$turkce->id] = ['correct' => 33, 'wrong' => 4, 'blank' => 3];

        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
            'rank_institution' => '3', 'total_institution' => '42',
            'rank_district' => '', 'total_district' => '',
            'rank_city' => '', 'total_city' => '',
            'rank_country' => '12400', 'total_country' => '180000',
            'note' => 'Türkçe paragrafta çok iyi; matematikte işlem hatası çoğalmış.',
            'subjects' => $girdi,
        ])
            ->assertRedirect(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->assertSessionHas('success', 'Deneme sonucu kaydedildi.');

        $sonuc = ExamResult::with('subjects')->sole();
        $this->assertSame($ogrenci->id, $sonuc->student_id);
        $this->assertSame($deneme->id, $sonuc->exam_event_id);
        $this->assertSame($yonetici->id, $sonuc->entered_by);
        $this->assertSame(3, $sonuc->rank_institution);
        $this->assertNull($sonuc->rank_city);
        $this->assertSame('180.000 kişide 12.400.', $sonuc->rankLabel('country'));
        $this->assertSame(32.0, $sonuc->totalNet());
        $this->assertCount(count($girdi), $sonuc->subjects);

        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->assertOk()
            ->assertSee('Deneme sonucu kaydedildi.')
            ->assertSee('value="33"', false)
            ->assertSee('value="180000"', false)
            ->assertSee('matematikte işlem hatası çoğalmış.');
    }

    public function test_the_correct_wrong_blank_boxes_are_labelled_when_prefilled(): void
    {
        $this->markTestSkipped('BUG: Doğru/Yanlış/Boş inputs are labelled only by placeholder, hidden by the prefilled 0');

        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $html = $this->actingAs($this->yonetici())->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->assertOk()
            ->assertSee('value="0"', false)
            ->getContent();

        // Kutular 0 ile dolu geldigi icin placeholder hic gorunmez; etiket
        // (label ya da aria-label) placeholder disinda bir yerde olmali.
        $dersBolumu = \Illuminate\Support\Str::between($html, 'Ders bazlı sonuç', 'Sıralamalar');
        $placeholdersiz = preg_replace('/placeholder="[^"]*"/', '', $dersBolumu);
        foreach (['Doğru', 'Yanlış', 'Boş'] as $etiket) {
            $this->assertStringContainsString($etiket, $placeholdersiz, "'{$etiket}' kutusunun gorunur etiketi yok");
        }
    }

    public function test_saving_twice_updates_the_same_result(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
            'rank_institution' => '5', 'total_institution' => '40',
            'subjects' => $this->tytGirdisi($ogrenci, $deneme, 10, 4),
        ])->assertRedirect();

        // Siralama sonradan aciklandi / cift tiklama
        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
            'rank_institution' => '', 'total_institution' => '',
            'rank_country' => '900', 'total_country' => '150000',
            'subjects' => $this->tytGirdisi($ogrenci, $deneme, 12, 4),
        ])->assertRedirect();

        $sonuc = ExamResult::with('subjects')->sole();
        $this->assertNull($sonuc->rank_institution);
        $this->assertSame(900, $sonuc->rank_country);
        $dersSayisi = count($this->tytGirdisi($ogrenci, $deneme));
        $this->assertSame($dersSayisi, ExamResultSubject::count());
        $this->assertSame(11.0 * $dersSayisi, $sonuc->totalNet());
    }

    public function test_a_flexible_exam_result_can_be_entered(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $serbest = $this->serbest('2026-09-01', '2026-10-31');

        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$serbest, $ogrenci]))
            ->assertOk()->assertSee('Hız ve Renk Serbest')->assertSee('TYT Türkçe');

        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$serbest, $ogrenci]), [
            'subjects' => $this->tytGirdisi($ogrenci, $serbest),
        ])->assertRedirect(route('admin.exam-results.edit', [$serbest, $ogrenci]));

        $this->assertSame(1, ExamResult::where('exam_event_id', $serbest->id)->count());
    }

    public function test_result_counts_and_rankings_are_validated(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $turkce = $this->ders('tyt_turkce');
        $formdan = route('admin.exam-results.edit', [$deneme, $ogrenci]);

        $this->actingAs($yonetici)->from($formdan)
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => [$turkce->id => ['correct' => 201, 'wrong' => -1, 'blank' => 'beş']],
            ])
            ->assertRedirect($formdan)
            ->assertSessionHasErrors(["subjects.{$turkce->id}.correct", "subjects.{$turkce->id}.wrong", "subjects.{$turkce->id}.blank"]);

        $this->actingAs($yonetici)->from($formdan)
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => '0',
                'total_country' => '1.240',
                'note' => str_repeat('ğ', 1001),
            ])
            ->assertSessionHasErrors(['subjects', 'rank_institution', 'total_country', 'note']);

        $this->assertSame(0, ExamResult::count());
    }

    public function test_a_rank_cannot_exceed_the_number_of_participants(): void
    {
        $this->markTestSkipped('BUG: rank larger than participant count is accepted (swapped fields saved as "87 kişide 1.240.")');

        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        // Yonetici sira ve katilimci kutularini karistirdi
        $this->actingAs($yonetici)->from(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => '1240', 'total_institution' => '87',
                'subjects' => $this->tytGirdisi($ogrenci, $deneme),
            ])
            ->assertSessionHasErrors('rank_institution');

        $this->assertSame(0, ExamResult::count());
    }

    public function test_an_unknown_subject_is_a_validation_error_not_a_server_error(): void
    {
        $this->markTestSkipped('BUG: posting a subject id that does not exist returns 500 (FK violation) instead of a validation error');

        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $this->actingAs($yonetici)->from(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => [999999 => ['correct' => 10, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertRedirect(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->assertSessionHasErrors();

        $this->assertSame(0, ExamResult::count());
    }

    public function test_result_routes_refuse_non_admins_and_non_students(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');
        $girdi = ['subjects' => $this->tytGirdisi($ogrenci, $deneme)];

        $this->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))->assertRedirect(route('login'));

        foreach ([$ogrenci, User::factory()->parent()->create(), $this->koc()] as $kullanici) {
            $this->actingAs($kullanici)->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))->assertForbidden();
            $this->actingAs($kullanici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), $girdi)->assertForbidden();
        }

        // Ogrenci olmayan birine sonuc girilmez
        $yonetici = $this->yonetici();
        $veli = User::factory()->parent()->create();
        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$deneme, $veli]))->assertNotFound();
        $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $veli]), $girdi)->assertNotFound();
        $this->actingAs($yonetici)->get('/yonetim/denemeler/999999/ogrenci/' . $ogrenci->id . '/sonuc')->assertNotFound();

        $this->assertSame(0, ExamResult::count());
    }

    public function test_a_student_with_no_matching_subjects_gets_an_empty_state_not_a_crash(): void
    {
        // Dil ogrencisi AYT'ye girmez: AYT'de alanina uyan ders yok
        $ogrenci = $this->ogrenci(['field' => 'dil']);
        $ayt = $this->deneme('2026-09-27', ['exam_type' => 'ayt']);

        $this->actingAs($this->yonetici())->get(route('admin.exam-results.edit', [$ayt, $ogrenci]))
            ->assertOk()
            ->assertSee('Önce ders tanımlamalısın')
            ->assertDontSee('name="subjects[', false);
    }

    // === Deneme raporlari (ExamReportController) ==============================

    public function test_admin_sees_the_reports_page_with_events_and_reports(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27', ['title' => 'TG TYT 3']);
        $hazir = ExamReport::factory()->analyzed()->create([
            'student_id' => $ogrenci->id, 'exam_event_id' => $deneme->id, 'title' => 'TG TYT 3 — sonuç',
        ]);
        $hatali = ExamReport::factory()->create([
            'student_id' => $ogrenci->id, 'title' => 'Kurum Denemesi 2', 'status' => ExamReport::FAILED,
            'error' => 'Yapay zeka servisi hata döndü (502).',
        ]);
        $baskasininki = ExamReport::factory()->create(['title' => 'Başka Öğrencinin Raporu']);

        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $ogrenci))
            ->assertOk()
            ->assertSee('Deneme Raporları: Çağrı Işıkoğlu')
            ->assertSee(route('admin.users.edit', $ogrenci))
            // Sonuc girisi baglantisi
            ->assertSee('Deneme sonucu gir')
            ->assertSee(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            // Yukleme formu
            ->assertSee('PDF yükle')
            ->assertSee('action="' . route('admin.exam-reports.store', $ogrenci) . '"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            // Raporlar
            ->assertSee('TG TYT 3 — sonuç')
            ->assertSee('Analiz hazır')
            ->assertSee('Problemler')
            ->assertSee('Yeniden analiz et')
            ->assertSee(route('admin.exam-reports.pdf', $hazir))
            ->assertSee(route('admin.exam-reports.analyze', $hazir))
            ->assertSee(route('admin.exam-reports.destroy', $hazir))
            ->assertSee('Kurum Denemesi 2')
            ->assertSee('Analiz başarısız')
            ->assertSee('Yapay zeka servisi hata döndü (502).')
            ->assertSee(route('admin.exam-reports.analyze', $hatali))
            ->assertDontSee('Başka Öğrencinin Raporu');

        $this->assertNotNull($baskasininki->id);
    }

    public function test_the_reports_page_for_a_student_with_nothing_yet(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.exam-reports.index', $this->ogrenci()))
            ->assertOk()
            ->assertSee('Bu öğrenci için henüz rapor yüklenmedi.')
            ->assertDontSee('Deneme sonucu gir');
    }

    public function test_admin_uploads_a_pdf_with_a_turkish_title_and_it_is_analyzed(): void
    {
        $this->apiDonsun($this->analiz());
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('2026-09-27');

        $this->actingAs($yonetici)->post(route('admin.exam-reports.store', $ogrenci), [
            'title' => 'Türkiye Geneli TYT 3 — sonuç (Işıl Çağ)',
            'exam_event_id' => (string) $deneme->id,
            'pdf' => $this->pdf('Sonuç Belgesi Şubat.pdf'),
        ])
            ->assertRedirect(route('admin.exam-reports.index', $ogrenci))
            ->assertSessionHas('success', 'Rapor yüklendi ve analiz edildi.');

        $rapor = ExamReport::sole();
        $this->assertSame(ExamReport::DONE, $rapor->status);
        $this->assertSame('Türkiye Geneli TYT 3 — sonuç (Işıl Çağ)', $rapor->title);
        $this->assertSame($deneme->id, $rapor->exam_event_id);
        $this->assertSame($yonetici->id, $rapor->uploaded_by);
        $this->assertSame('Problemler', $rapor->analysis['weak_areas'][0]['topic']);
        $this->assertNotNull($rapor->analyzed_at);
        Storage::disk('yukleme')->assertExists($rapor->file_path);

        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $ogrenci))
            ->assertOk()
            ->assertSee('Rapor yüklendi ve analiz edildi.')
            ->assertSee('Türkiye Geneli TYT 3 — sonuç (Işıl Çağ)')
            ->assertSee('Problemler konusunu tekrar et.');
    }

    public function test_an_upload_without_an_ai_key_keeps_the_file_and_says_why(): void
    {
        config(['services.openai.api_key' => null]);
        $this->app->forgetInstance(\App\Services\ExamReportAnalyzer::class);
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();

        $this->actingAs($yonetici)->post(route('admin.exam-reports.store', $ogrenci), [
            'title' => 'Kurum Denemesi 5',
            'pdf' => $this->pdf(),
        ])
            ->assertRedirect(route('admin.exam-reports.index', $ogrenci))
            ->assertSessionHas('error');

        $rapor = ExamReport::sole();
        $this->assertSame(ExamReport::FAILED, $rapor->status);
        $this->assertNull($rapor->exam_event_id);
        $this->assertStringContainsString('OPENAI_API_KEY', $rapor->error);
        Storage::disk('yukleme')->assertExists($rapor->file_path);

        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $ogrenci))
            ->assertOk()->assertSee('Analiz başarısız')->assertSee('Analiz et');
    }

    public function test_the_upload_form_is_validated(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci();
        $sayfa = route('admin.exam-reports.index', $ogrenci);

        $this->actingAs($yonetici)->from($sayfa)
            ->post(route('admin.exam-reports.store', $ogrenci), [
                'title' => '',
                'exam_event_id' => '999999',
                'pdf' => UploadedFile::fake()->image('karne.jpg'),
            ])
            ->assertRedirect($sayfa)
            ->assertSessionHasErrors(['title', 'exam_event_id', 'pdf']);

        $this->actingAs($yonetici)->from($sayfa)
            ->post(route('admin.exam-reports.store', $ogrenci), [
                'title' => 'Büyük dosya',
                'pdf' => UploadedFile::fake()->create('buyuk.pdf', 10241, 'application/pdf'),
            ])
            ->assertSessionHasErrors('pdf');

        $this->actingAs($yonetici)->from($sayfa)
            ->post(route('admin.exam-reports.store', $ogrenci), ['title' => 'Dosyasız'])
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, ExamReport::count());
        $this->assertSame([], Storage::disk('yukleme')->allFiles());
        Http::assertNothingSent();
    }

    public function test_admin_reanalyzes_a_report(): void
    {
        // Ilk yeniden analiz basarili, ikincisi servis hatasi
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($this->analiz(['summary' => 'Yeniden okundu: Türkçe güçlü.']))]]]])
            ->push('patladı', 503)]);
        $yonetici = $this->yonetici();
        $rapor = ExamReport::factory()->create(['status' => ExamReport::FAILED, 'error' => 'zaman aşımı']);
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF-1.4');
        $sayfa = route('admin.exam-reports.index', $rapor->student_id);

        $this->actingAs($yonetici)->from($sayfa)->post(route('admin.exam-reports.analyze', $rapor))
            ->assertRedirect($sayfa)
            ->assertSessionHas('success', 'Analiz tamamlandı.');

        $rapor->refresh();
        $this->assertSame(ExamReport::DONE, $rapor->status);
        $this->assertNull($rapor->error);
        $this->assertSame('Yeniden okundu: Türkçe güçlü.', $rapor->analysis['summary']);

        // Servis hata verirse durum 'failed' olur
        $this->actingAs($yonetici)->from($sayfa)->post(route('admin.exam-reports.analyze', $rapor))
            ->assertRedirect($sayfa)
            ->assertSessionHas('error');
        $this->assertSame(ExamReport::FAILED, $rapor->fresh()->status);
        $this->assertStringContainsString('503', $rapor->fresh()->error);
    }

    public function test_admin_downloads_the_pdf_inline(): void
    {
        $rapor = ExamReport::factory()->create(['title' => 'Türkiye Geneli TYT 3 — sonuç']);
        Storage::disk('yukleme')->put($rapor->file_path, "%PDF-1.4\niçerik");

        $yanit = $this->actingAs($this->yonetici())->get(route('admin.exam-reports.pdf', $rapor));

        $yanit->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('turkiye-geneli-tyt-3-sonuc.pdf', $yanit->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('inline', $yanit->headers->get('Content-Disposition'));
        $this->assertSame("%PDF-1.4\niçerik", $yanit->streamedContent());
    }

    public function test_a_failed_reanalysis_keeps_the_previous_good_analysis_visible(): void
    {
        $this->markTestSkipped('BUG: a failed re-analysis hides the previous successful analysis behind "Analiz yapılamadı"');

        Http::fake(['api.openai.com/*' => Http::response('patladı', 503)]);
        $yonetici = $this->yonetici();
        $rapor = ExamReport::factory()->analyzed()->create(['title' => 'TG TYT 3 — sonuç']);
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF-1.4');

        $this->actingAs($yonetici)->from(route('admin.exam-reports.index', $rapor->student_id))
            ->post(route('admin.exam-reports.analyze', $rapor))
            ->assertSessionHas('error');

        // Ogrencinin gordugu iyi analiz gecici bir servis hatasiyla kaybolmamali
        $this->assertTrue($rapor->fresh()->isAnalyzed());
        $this->actingAs($rapor->student)->get(route('user.exam-reports.show', $rapor))
            ->assertOk()
            ->assertSee('Problemler')
            ->assertDontSee('Analiz yapılamadı');
    }

    public function test_a_report_whose_file_is_gone_is_a_404_not_a_500(): void
    {
        $this->markTestSkipped('BUG: PDF link returns 500 (UnableToRetrieveMetadata) when the stored file is missing');

        $rapor = ExamReport::factory()->create();

        $this->actingAs($this->yonetici())->get(route('admin.exam-reports.pdf', $rapor))->assertNotFound();
    }

    public function test_admin_removes_a_report_and_its_file(): void
    {
        $yonetici = $this->yonetici();
        $rapor = ExamReport::factory()->analyzed()->create();
        $kalan = ExamReport::factory()->create(['student_id' => $rapor->student_id, 'title' => 'Kalan Rapor']);
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF');
        Storage::disk('yukleme')->put($kalan->file_path, '%PDF');

        $this->actingAs($yonetici)->delete(route('admin.exam-reports.destroy', $rapor))
            ->assertRedirect(route('admin.exam-reports.index', $rapor->student_id))
            ->assertSessionHas('success', 'Rapor kaldırıldı.');

        Storage::disk('yukleme')->assertMissing($rapor->file_path);
        Storage::disk('yukleme')->assertExists($kalan->file_path);
        $this->assertSame([$kalan->id], ExamReport::pluck('id')->all());

        // Cift tiklama: ikinci silme 404
        $this->actingAs($yonetici)->delete(route('admin.exam-reports.destroy', $rapor))->assertNotFound();
    }

    public function test_report_routes_refuse_non_admins_and_non_students(): void
    {
        $rapor = ExamReport::factory()->analyzed()->create();
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF');
        $sahibi = $rapor->student;

        $this->get(route('admin.exam-reports.index', $sahibi))->assertRedirect(route('login'));
        $this->get(route('admin.exam-reports.pdf', $rapor))->assertRedirect(route('login'));

        // Raporun sahibi bile yonetici ucunu kullanamaz
        foreach ([$sahibi, User::factory()->parent()->create(), $this->koc()] as $kullanici) {
            $this->actingAs($kullanici)->get(route('admin.exam-reports.index', $sahibi))->assertForbidden();
            $this->actingAs($kullanici)->post(route('admin.exam-reports.store', $sahibi), ['title' => 'X', 'pdf' => $this->pdf()])->assertForbidden();
            $this->actingAs($kullanici)->post(route('admin.exam-reports.analyze', $rapor))->assertForbidden();
            $this->actingAs($kullanici)->get(route('admin.exam-reports.pdf', $rapor))->assertForbidden();
            $this->actingAs($kullanici)->delete(route('admin.exam-reports.destroy', $rapor))->assertForbidden();
        }

        $this->assertSame(1, ExamReport::count());
        Storage::disk('yukleme')->assertExists($rapor->file_path);

        // Ogrenci olmayan (koc, yonetici) icin rapor sayfasi yok
        $yonetici = $this->yonetici();
        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $this->koc()))->assertNotFound();
        $this->actingAs($yonetici)->post(route('admin.exam-reports.store', $yonetici), ['title' => 'X', 'pdf' => $this->pdf()])->assertNotFound();
        $this->assertSame(1, ExamReport::count());
    }
}
