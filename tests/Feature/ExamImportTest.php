<?php

namespace Tests\Feature;

use App\Contracts\ExamPdfReader;
use App\Models\ExamEvent;
use App\Models\ExamImport;
use App\Models\ExamImportRow;
use App\Models\ExamResult;
use App\Models\Notification;
use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Models\WeakTopic;
use App\Services\ExamImport\ExamPdfReadException;
use App\Support\StudentNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kurum geneli deneme sonuc PDF'i (1 Ekim 2026).
 *
 * Yapay zeka sahte okuyucuyla (FakeExamReader) taklit edilir; ag yok.
 * Adlar uydurma: gercek PDF'teki ogrenci adlari depoya girmez.
 */
class ExamImportTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;
    private User $elif;
    private User $mert;
    private ExamEvent $deneme;
    private FakeExamReader $okuyucu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 10:00', config('kafe.timezone')));
        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');

        $this->okuyucu = new FakeExamReader;
        $this->app->instance(ExamPdfReader::class, $this->okuyucu);

        $this->yonetici = User::factory()->admin()->create();
        $this->elif = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Elif Yıldırım', 'grade' => '12', 'field' => 'say']);
        $this->mert = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Mert Işık Can', 'grade' => '12', 'field' => 'say']);
        $this->deneme = ExamEvent::create(['title' => 'Hız ve Renk TYT 2', 'exam_type' => 'tyt', 'exam_date' => '2026-09-27']);
    }

    private function yukle(): ExamImport
    {
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->deneme->id,
            'pdf' => UploadedFile::fake()->createWithContent('kurum.pdf', "%PDF-1.7\n% sahte\n"),
        ])->assertRedirect();

        return ExamImport::sole();
    }

    private function isle(ExamImport $aktarim): array
    {
        return $this->actingAs($this->yonetici)
            ->postJson(route('admin.exam-imports.process', $aktarim))
            ->assertOk()->json();
    }

    private function hepsiniOku(ExamImport $aktarim): void
    {
        for ($i = 0; $i < 10 && $aktarim->fresh()->isProcessing(); $i++) {
            $this->isle($aktarim);
        }
    }

    /** Depolama yazamazsa 500 degil, sebebiyle hata; kayit acilmaz. */
    public function test_a_storage_failure_is_shown_with_its_reason(): void
    {
        config(['filesystems.disks.bozuk' => ['driver' => 'local', 'root' => '/proc/kral-kafe-yok', 'throw' => false],
            'filesystems.uploads' => 'bozuk']);

        $this->actingAs($this->yonetici)->from(route('admin.exam-imports.index'))->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->deneme->id,
            'pdf' => UploadedFile::fake()->createWithContent('kurum.pdf', "%PDF-1.7\n% sahte\n"),
        ])->assertRedirect(route('admin.exam-imports.index'))
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Dosya depolamaya yazılamadı (bozuk):'));

        $this->assertSame(0, ExamImport::count());
    }

    /** S3 anahtari bossa SDK'nin anlasilmaz hatasi yerine eksik degiskenler. */
    public function test_missing_s3_settings_are_named(): void
    {
        config(['filesystems.disks.bulut' => ['driver' => 's3', 'key' => '', 'secret' => null, 'region' => 'ap-southeast-1',
            'bucket' => 'Kafe', 'endpoint' => 'https://ornek.supabase.co/storage/v1/s3'], 'filesystems.uploads' => 'bulut']);

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->deneme->id,
            'pdf' => UploadedFile::fake()->createWithContent('kurum.pdf', "%PDF-1.7\n"),
        ])->assertSessionHas('error', "Dosya depolamaya yazılamadı (bulut): Vercel'de tanımlı değil: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY.");

        $this->assertSame(0, ExamImport::count());
    }

    // --- Ad esleme -------------------------------------------------------------

    public function test_names_are_normalized_letter_by_letter(): void
    {
        $this->assertSame('isik ozgur', StudentNameMatcher::normalize('IŞIK ÖZGÜR'));
        $this->assertSame('ipek arslan', StudentNameMatcher::normalize('İPEK ARSLAN'));
        $this->assertSame('cagla simsek gunes', StudentNameMatcher::normalize('Çağla  Şimşek-Güneş'));
    }

    public function test_matching_is_exact_then_partial_and_never_guesses(): void
    {
        $adlar = [1 => 'elif yildirim', 2 => 'mert isik can', 3 => 'ruzgar demir', 4 => 'ali kaya', 5 => 'ali veli'];

        $this->assertSame([1, ExamImportRow::AUTO], StudentNameMatcher::find('elif yildirim', $adlar));
        // Yalnizca ad yazilmis: tek aday varsa oneri, yonetici onaylar.
        $this->assertSame([3, ExamImportRow::SUGGESTED], StudentNameMatcher::find('ruzgar', $adlar));
        // Ikinci ad PDF'te yok.
        $this->assertSame([2, ExamImportRow::SUGGESTED], StudentNameMatcher::find('mert can', $adlar));
        // Iki "ali": tahmin yok.
        $this->assertSame([null, ExamImportRow::NONE], StudentNameMatcher::find('ali', $adlar));
        $this->assertSame([null, ExamImportRow::NONE], StudentNameMatcher::find('zeynep', $adlar));
    }

    // --- Okuma -----------------------------------------------------------------

    public function test_the_pdf_is_read_one_step_at_a_time_and_matched(): void
    {
        $aktarim = $this->yukle();
        $this->assertSame(ExamImport::UPLOADED, $aktarim->status);

        $durum = $this->isle($aktarim);
        $this->assertSame(['reading', 0, 2], [$durum['status'], $durum['done'], $durum['total']]);
        $this->assertSame(1, $this->okuyucu->dizinCagrisi);

        $satirlar = $aktarim->rows()->get()->keyBy('name');
        $this->assertSame([$this->elif->id, ExamImportRow::AUTO], [$satirlar['ELİF YILDIRIM']->student_id, $satirlar['ELİF YILDIRIM']->match]);
        $this->assertSame([$this->mert->id, ExamImportRow::SUGGESTED], [$satirlar['MERT CAN']->student_id, $satirlar['MERT CAN']->match]);
        $this->assertSame(ExamImportRow::NONE, $satirlar['DIŞARIDAN BİRİ']->match);
        // Yalnizca bu denemenin (TYT) ders kodlari istendi.
        $this->assertContains('tyt_kimya', $this->okuyucu->kodlar);
        $this->assertNotContains('ayt_fizik', $this->okuyucu->kodlar);

        $this->assertSame([1, 2], [$this->isle($aktarim)['done'], $this->isle($aktarim)['done']]);
        $this->assertSame(ExamImport::REVIEW, $aktarim->fresh()->status);
        $this->assertSame([4, 5], $this->okuyucu->okunanSayfalar);

        // Liste (30D 4Y) ile karne (30D 4Y) ayni: uyari yok.
        $this->assertSame([], $aktarim->rows()->where('name', 'ELİF YILDIRIM')->sole()->data['mismatch']);
    }

    /** Liste ve karne farkli okunursa yonetici uyarilir. */
    public function test_a_list_and_card_mismatch_is_flagged(): void
    {
        $this->okuyucu->listeTurkceDogru = 31;
        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);

        $this->assertSame(['tyt_turkce'], $aktarim->rows()->where('name', 'ELİF YILDIRIM')->sole()->data['mismatch']);
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.show', $aktarim))
            ->assertSee('liste ile karne farklı (TYT Türkçe)');
    }

    public function test_a_failure_stops_and_continue_does_not_reread_finished_cards(): void
    {
        $aktarim = $this->yukle();
        $this->isle($aktarim);
        $this->isle($aktarim);

        $this->okuyucu->hata = 'Yapay zekâ servisi hata döndü (500).';
        $durum = $this->isle($aktarim);
        $this->assertSame(['failed', 'Yapay zekâ servisi hata döndü (500).'], [$durum['status'], $durum['message']]);

        $this->okuyucu->hata = null;
        $this->isle($aktarim);

        $this->assertSame(ExamImport::REVIEW, $aktarim->fresh()->status);
        $this->assertSame([4, 5], $this->okuyucu->okunanSayfalar);
    }

    /** Zaman asimi aktarimi durdurmaz; ayni adim 2 kez daha denenir (5 Ekim 2026). */
    public function test_a_timeout_is_retried_twice_on_the_server_then_stops(): void
    {
        $aktarim = $this->yukle();
        $this->isle($aktarim);
        $this->okuyucu->hata = ExamPdfReadException::timeout()->getMessage();

        $durum = $this->isle($aktarim);
        $this->assertSame('reading', $durum['status']);
        $this->assertStringEndsWith('Yeniden deneniyor (1/2).', $durum['message']);
        $this->assertStringEndsWith('Yeniden deneniyor (2/2).', $this->isle($aktarim)['message']);
        $this->assertSame('failed', $this->isle($aktarim)['status']);

        // "Devam et": sayac sifirdan; basarili adim hatayi siler.
        $this->okuyucu->hata = null;
        $durum = $this->isle($aktarim);
        $this->assertSame(['reading', 1, null], [$durum['status'], $durum['done'], $durum['message']]);
        $this->assertSame(0, $aktarim->fresh()->meta['retries']);
    }

    // --- Kuyruk (5 Ekim 2026) -------------------------------------------------

    private function ikinciAktarim(): ExamImport
    {
        $deneme = ExamEvent::create(['title' => 'Yayın Denizi TYT 1', 'exam_type' => 'tyt', 'exam_date' => '2026-09-28']);
        Storage::disk('yukleme')->put('deneme-aktarimlari/ikinci.pdf', "%PDF-1.7\n");

        return ExamImport::create(['exam_event_id' => $deneme->id, 'file_path' => 'deneme-aktarimlari/ikinci.pdf', 'uploaded_by' => $this->yonetici->id]);
    }

    public function test_a_second_upload_waits_in_the_queue_and_is_read_after_the_first(): void
    {
        $birinci = $this->yukle();
        $ikinci = $this->ikinciAktarim();

        // Ikincinin sayfasi acik, birincininki kapali: once birinci ilerler.
        $durum = $this->isle($ikinci);
        $this->assertSame(['uploaded', 1, 'Hız ve Renk TYT 2'], [$durum['status'], $durum['queue'], $durum['ahead']['title']]);
        $this->assertSame(ExamImport::READING, $birinci->fresh()->status);
        $this->assertSame(1, $this->okuyucu->dizinCagrisi);

        $this->isle($ikinci);
        $this->isle($ikinci);
        $this->assertSame(ExamImport::REVIEW, $birinci->fresh()->status);
        $this->assertSame([4, 5], $this->okuyucu->okunanSayfalar);

        // Sira geldi: ikinci okunur.
        $durum = $this->isle($ikinci);
        $this->assertSame(['reading', 0, null], [$durum['status'], $durum['queue'], $durum['ahead']]);
        $this->assertSame(2, $this->okuyucu->dizinCagrisi);

        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.index'))->assertOk()->assertDontSee('Sırada');
    }

    public function test_the_queue_is_shown_on_the_pages(): void
    {
        $this->yukle();

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->deneme->id,
            'pdf' => UploadedFile::fake()->createWithContent('kurum.pdf', "%PDF-1.7\n"),
        ])->assertSessionHas('success', 'PDF yüklendi. Önünde 1 deneme okunuyor; bitince bu deneme kendiliğinden okunur. Sayfa açık kalsın.');

        $ikinci = ExamImport::latest('id')->first();
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.show', $ikinci))
            ->assertOk()->assertSee('Sırada')->assertSee('önünde 1 deneme');
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.index'))
            ->assertOk()->assertSee('Sırada · önünde 1');
    }

    /** Baska bir sekme okurken ikinci istek okumaz, bekler (ayni karne iki kez okunmaz). */
    public function test_a_step_waits_while_another_read_holds_the_lock(): void
    {
        $aktarim = $this->yukle();
        $kilit = Cache::lock('exam-import-reader', 75);
        $kilit->get();

        $durum = $this->isle($aktarim);
        $this->assertTrue($durum['waiting']);
        $this->assertSame(0, $this->okuyucu->dizinCagrisi);

        $kilit->release();
        $this->assertFalse($this->isle($aktarim)['waiting']);
        $this->assertSame(1, $this->okuyucu->dizinCagrisi);
    }

    /** Hata alan aktarim sirayi tikamaz. */
    public function test_a_failed_import_does_not_block_the_queue(): void
    {
        $birinci = $this->yukle();
        $ikinci = $this->ikinciAktarim();
        $this->isle($birinci);
        $this->okuyucu->hata = 'Yapay zekâ servisi hata döndü (500).';
        $this->isle($birinci);
        $this->assertSame(ExamImport::FAILED, $birinci->fresh()->status);

        $this->okuyucu->hata = null;
        $durum = $this->isle($ikinci);
        $this->assertSame([0, 'reading'], [$durum['queue'], $durum['status']]);
        $this->assertSame(ExamImport::FAILED, $birinci->fresh()->status);
    }

    // --- Kontrol ve yayin ------------------------------------------------------

    public function test_publishing_waits_for_unresolved_rows(): void
    {
        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.publish', $aktarim))
            ->assertSessionHas('error', '2 satır kontrol bekliyor: öğrenciyi seçin ya da atlayın.');
        $this->assertSame(0, ExamResult::count());
    }

    private function onaylaVeYayinla(ExamImport $aktarim): void
    {
        $satirlar = $aktarim->rows()->get()->keyBy('name');
        $this->actingAs($this->yonetici)->patch(route('admin.exam-imports.rows.update', $satirlar['MERT CAN']), ['student_id' => $this->mert->id])->assertSessionHasNoErrors();
        $this->actingAs($this->yonetici)->patch(route('admin.exam-imports.rows.update', $satirlar['DIŞARIDAN BİRİ']), ['skip' => 1]);

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.publish', $aktarim))
            ->assertSessionHas('success', '2 öğrencinin sonucu yayınlandı; öğrenci, veli ve koçlara bildirim gitti.');
    }

    public function test_publishing_writes_results_ranks_topics_and_weak_topics(): void
    {
        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);
        $this->onaylaVeYayinla($aktarim);

        $sonuc = ExamResult::where('student_id', $this->elif->id)->with('subjects.subject')->sole();
        $this->assertSame($this->deneme->id, $sonuc->exam_event_id);
        $this->assertSame([2, 9, 18, 302, 43, 1206], [$sonuc->rank_institution, $sonuc->total_institution, $sonuc->rank_city, $sonuc->total_city, $sonuc->rank_country, $sonuc->total_country]);
        $this->assertSame('409.027', (string) $sonuc->score);
        // Kimya: 1D 3Y 3B; "other" (secmeli felsefe) yazilmaz.
        $kimya = $sonuc->subjects->firstWhere('subject.code', 'tyt_kimya');
        $this->assertSame([1, 3, 3], [$kimya->correct, $kimya->wrong, $kimya->blank]);
        $this->assertSame(3, $sonuc->subjects->count());
        $this->assertEqualsWithDelta(34.0, $sonuc->totalNet(), 0.01);

        // Eksik konu kuralla: 2 soruda 0 dogru eksik; 1 soruluk tek yanlis ve
        // 20 soruda 16 dogru (%80) degil.
        $eksikler = WeakTopic::where('student_id', $this->elif->id)->pluck('topic')->all();
        $this->assertSame(['Kimyanın Temel Kanunları'], $eksikler);
        $this->assertSame('exam', WeakTopic::where('student_id', $this->elif->id)->sole()->source);

        // Yeniden yayin cift kayit acmaz.
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.publish', $aktarim));
        $this->assertSame(2, ExamResult::count());
        $this->assertSame(1, WeakTopic::where('student_id', $this->elif->id)->count());
    }

    public function test_the_student_parent_and_coach_are_notified_once(): void
    {
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($this->elif->id);
        $koc = User::factory()->create(['role' => 'coach']);
        $koc->coachStudents()->attach($this->elif->id);

        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);
        $this->onaylaVeYayinla($aktarim);
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.publish', $aktarim));

        $ogrenciye = Notification::where('user_id', $this->elif->id)->sole();
        $this->assertSame('Deneme sonucun yüklendi: Hız ve Renk TYT 2', $ogrenciye->title);
        $this->assertStringContainsString('Toplam net 34,00', $ogrenciye->body);
        $this->assertStringNotContainsString('Paragraf', $ogrenciye->body);
        $this->assertStringContainsString('Kimyanın Temel Kanunları', $ogrenciye->body);
        $this->assertSame(1, Notification::where('user_id', $veli->id)->count());
        $this->assertStringContainsString('plana ekleyebilirsin', Notification::where('user_id', $koc->id)->sole()->body);
    }

    public function test_each_student_sees_only_their_own_result(): void
    {
        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);
        $this->onaylaVeYayinla($aktarim);

        $sonuc = \App\Models\ExamResult::where('student_id', $this->elif->id)->sole();
        $this->actingAs($this->elif)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Hız ve Renk TYT 2')
            ->assertSee('Puan 409,027')
            ->assertSee(route('user.exam-results.show', $sonuc), false)
            ->assertDontSee('Mert')
            ->assertDontSee('DIŞARIDAN');

        // Detay: dersler, siralar, eksik konular, konu konu sonuclar.
        $this->actingAs($this->elif)->get(route('user.exam-results.show', $sonuc))
            ->assertOk()
            ->assertSee('Eksik konular')
            ->assertSee('Kimyanın Temel Kanunları')
            ->assertSee('302 kişide 18.')
            ->assertSee('Konu konu sonuçlar')
            ->assertDontSee('Mert');

        // Baska ogrencinin sonucu acilmaz.
        $mertinki = \App\Models\ExamResult::where('student_id', $this->mert->id)->first();
        if ($mertinki) {
            $this->actingAs($this->elif)->get(route('user.exam-results.show', $mertinki))->assertNotFound();
        }

        // Kurum PDF'i ve aktarim sayfasi yalnizca yoneticide.
        $this->actingAs($this->elif)->get(route('admin.exam-imports.pdf', $aktarim))->assertForbidden();
        $this->actingAs($this->elif)->get(route('admin.exam-imports.show', $aktarim))->assertForbidden();
    }

    public function test_the_coach_gets_plan_suggestions_from_the_exam(): void
    {
        $koc = User::factory()->create(['role' => 'coach']);
        $koc->coachStudents()->attach($this->elif->id);
        $aktarim = $this->yukle();
        $this->hepsiniOku($aktarim);
        $this->onaylaVeYayinla($aktarim);

        $this->actingAs($koc)->get(route('coach.plan.show', $this->elif))
            ->assertOk()
            ->assertSee('Denemeden gelen öneriler')
            ->assertSee('Kimyanın Temel Kanunları');

        $this->actingAs($koc)->post(route('coach.topics.plan', WeakTopic::where('student_id', $this->elif->id)->sole()));
        $this->assertSame(StudyPlanItem::TAG_HOMEWORK, StudyPlanItem::sole()->tag);

        // Plana eklenen konu tekrar onerilmez.
        $this->actingAs($koc)->get(route('coach.plan.show', $this->elif))->assertDontSee('Denemeden gelen öneriler');
    }

    public function test_the_review_page_renders_every_state(): void
    {
        $aktarim = $this->yukle();
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.show', $aktarim))->assertOk()->assertSee('Okumaya başla');

        $this->hepsiniOku($aktarim);
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.show', $aktarim))
            ->assertOk()
            ->assertSee('ELİF YILDIRIM')
            ->assertSee('Kontrol et')
            ->assertSee('Sonuçları yayınla');

        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.index'))->assertOk()->assertSee('Hız ve Renk TYT 2');
    }
}

/** Sahte okuyucu: iki karneli, bir karnesiz uc ogrenci. */
class FakeExamReader implements ExamPdfReader
{
    public int $dizinCagrisi = 0;
    public array $okunanSayfalar = [];
    public array $kodlar = [];
    public ?string $hata = null;
    public int $listeTurkceDogru = 30;

    public function provider(): string
    {
        return 'anthropic';
    }

    public function readIndex(string $pdf, array $dersler): array
    {
        $this->dizinCagrisi++;
        $this->kodlar = array_keys($dersler);

        return [
            'exam' => ['name' => 'HIZ VE RENK DK TYT 2', 'date' => null],
            'participants' => ['institution' => 9, 'district' => 9, 'city' => 302, 'country' => 1206],
            'students' => [
                ['name' => 'ELİF YILDIRIM', 'class' => '12-A', 'card_page' => 4, 'score' => 409.027, 'subjects' => [['code' => 'tyt_turkce', 'label' => 'Türkçe', 'correct' => $this->listeTurkceDogru, 'wrong' => 4]]],
                ['name' => 'MERT CAN', 'class' => 'Mezun', 'card_page' => 5, 'score' => 350.5, 'subjects' => []],
                ['name' => 'DIŞARIDAN BİRİ', 'class' => '12-B', 'card_page' => null, 'score' => 200, 'subjects' => [['code' => 'tyt_turkce', 'label' => 'Türkçe', 'correct' => 10, 'wrong' => 8]]],
            ],
        ];
    }

    public function readCard(string $pdf, int $sayfa, string $ad, array $dersler): array
    {
        if ($this->hata !== null) {
            throw new ExamPdfReadException($this->hata);
        }
        $this->okunanSayfalar[] = $sayfa;

        return [
            'name' => $ad, 'class' => null, 'score' => $sayfa === 4 ? 409.027 : 350.5,
            'ranks' => [
                'branch' => ['rank' => 2, 'total' => 5], 'institution' => ['rank' => 2, 'total' => 9],
                'district' => ['rank' => 2, 'total' => 9], 'city' => ['rank' => 18, 'total' => 302],
                'country' => ['rank' => 43, 'total' => 1206],
            ],
            'subjects' => [
                ['code' => 'tyt_turkce', 'label' => 'Türkçe', 'questions' => 40, 'correct' => 30, 'wrong' => 4, 'blank' => 6],
                ['code' => 'tyt_kimya', 'label' => 'Kimya', 'questions' => 7, 'correct' => 1, 'wrong' => 3, 'blank' => null],
                ['code' => 'tyt_biyoloji', 'label' => 'Biyoloji', 'questions' => 6, 'correct' => 5, 'wrong' => 1, 'blank' => 0],
                ['code' => 'other', 'label' => 'Felsefe (Seçmeli)', 'questions' => 5, 'correct' => 0, 'wrong' => 0, 'blank' => 5],
            ],
            'topics' => [
                ['subject_code' => 'tyt_turkce', 'topic' => 'Paragraf Yorumu', 'questions' => 20, 'correct' => 16, 'wrong' => 4],
                ['subject_code' => 'tyt_kimya', 'topic' => 'Kimyanın Temel Kanunları', 'questions' => 2, 'correct' => 0, 'wrong' => 0],
                ['subject_code' => 'tyt_kimya', 'topic' => 'Kimya Bilimi', 'questions' => 1, 'correct' => 0, 'wrong' => 1],
                ['subject_code' => 'other', 'topic' => '', 'questions' => 0, 'correct' => 0, 'wrong' => 0],
            ],
        ];
    }
}
