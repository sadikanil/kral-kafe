<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\ExamReport;
use App\Models\User;
use App\Services\ExamReportAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deneme sonuc raporu: yonetici PDF yukler, yapay zeka okur, ogrenci gorur.
 */
class ExamReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => 'test-anahtari']);
        Storage::fake('yukleme');
    }

    private function analiz(): array
    {
        return [
            'exam' => ['name' => 'TG TYT 3', 'type' => 'TYT', 'date' => '2026-09-27'],
            'overall' => ['correct' => 90, 'wrong' => 20, 'blank' => 10, 'net' => 85, 'score' => 412.5, 'rank' => '1.250'],
            'subjects' => [['name' => 'Matematik', 'correct' => 20, 'wrong' => 10, 'blank' => 10, 'net' => 17.5]],
            'strong_areas' => [['subject' => 'Türkçe', 'topic' => 'Paragraf', 'evidence' => '18/18 doğru']],
            'weak_areas' => [['subject' => 'Matematik', 'topic' => 'Problemler', 'evidence' => '2/12 doğru']],
            'focus_suggestions' => ['Problemler konusunu tekrar et.'],
            'summary' => 'Türkçe netleri yüksek, matematik problemleri düşük.',
            'gereksiz' => 'atilmali',
        ];
    }

    private function apiDonsun(array $icerik): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($icerik)]]]])]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sonuc.pdf', "%PDF-1.4\n% sahte\n");
    }

    public function test_the_analyzer_sends_the_pdf_as_a_file_part_and_normalizes_the_answer(): void
    {
        $this->apiDonsun($this->analiz());

        $sonuc = (new ExamReportAnalyzer())->analyze('%PDF-1.4 ham', 'rapor.pdf');

        $this->assertTrue($sonuc['success']);
        $this->assertSame(85, $sonuc['data']['overall']['net']);
        $this->assertSame('Problemler', $sonuc['data']['weak_areas'][0]['topic']);
        $this->assertArrayNotHasKey('gereksiz', $sonuc['data']);

        Http::assertSent(function ($request) {
            $parca = collect($request->data()['messages'][1]['content'])->firstWhere('type', 'file');

            return $parca !== null
                && $parca['file']['filename'] === 'rapor.pdf'
                && str_starts_with($parca['file']['file_data'], 'data:application/pdf;base64,')
                && $request->data()['response_format']['type'] === 'json_object';
        });
    }

    public function test_the_analyzer_fails_softly_without_a_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $sonuc = (new ExamReportAnalyzer())->analyze('x');

        $this->assertFalse($sonuc['success']);
        $this->assertStringContainsString('OPENAI_API_KEY', $sonuc['error']);
        Http::assertNothingSent();
    }

    public function test_the_analyzer_reports_an_api_error(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('hata', 500)]);

        $sonuc = (new ExamReportAnalyzer())->analyze('x');

        $this->assertFalse($sonuc['success']);
        $this->assertStringContainsString('500', $sonuc['error']);
    }

    public function test_an_admin_uploads_a_pdf_and_the_student_sees_the_analysis(): void
    {
        $this->apiDonsun($this->analiz());
        $yonetici = User::factory()->admin()->create();
        $ogrenci = User::factory()->student()->create();
        $deneme = ExamEvent::factory()->create(['title' => 'TG TYT 3', 'exam_date' => '2026-09-27']);

        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $ogrenci))->assertOk()->assertSee('PDF yükle');

        $this->actingAs($yonetici)
            ->post(route('admin.exam-reports.store', $ogrenci), [
                'title' => 'TG TYT 3 sonuç',
                'exam_event_id' => $deneme->id,
                'pdf' => $this->pdf(),
            ])
            ->assertRedirect(route('admin.exam-reports.index', $ogrenci))
            ->assertSessionHas('success');

        $rapor = ExamReport::sole();
        $this->assertSame(ExamReport::DONE, $rapor->status);
        $this->assertSame($ogrenci->id, $rapor->student_id);
        $this->assertSame($yonetici->id, $rapor->uploaded_by);
        $this->assertSame('Problemler', $rapor->analysis['weak_areas'][0]['topic']);
        $this->assertStringStartsWith('deneme-raporlari/' . $ogrenci->id . '/', $rapor->file_path);
        $this->assertStringEndsWith('.pdf', $rapor->file_path);
        Storage::disk('yukleme')->assertExists($rapor->file_path);

        $this->actingAs($ogrenci)->get('/kullanici/deneme-raporlari')
            ->assertOk()->assertSee('TG TYT 3 sonuç')->assertSee('Analiz hazır');

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))
            ->assertOk()
            ->assertSee('Başarılı alanlar')
            ->assertSee('Paragraf')
            ->assertSee('Problemler')
            ->assertSee('2/12 doğru')
            ->assertSee('Problemler konusunu tekrar et.')
            ->assertSee('Sıralama')
            ->assertSee('1.250');

        $this->actingAs($ogrenci)->get(route('user.exam-reports.pdf', $rapor))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_only_the_owner_sees_a_report(): void
    {
        $rapor = ExamReport::factory()->analyzed()->create();
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF');
        $baskasi = User::factory()->student()->create();

        // Misafir ONCE: actingAs oturumu test boyunca acik birakir.
        $this->get(route('user.exam-reports.show', $rapor))->assertRedirect(route('login'));
        $this->actingAs($baskasi)->get(route('user.exam-reports.show', $rapor))->assertForbidden();
        $this->actingAs($baskasi)->get(route('user.exam-reports.pdf', $rapor))->assertForbidden();
        $this->actingAs($rapor->student)->get(route('user.exam-reports.show', $rapor))->assertOk();
    }

    public function test_only_a_pdf_is_accepted_and_only_by_an_admin(): void
    {
        $yonetici = User::factory()->admin()->create();
        $ogrenci = User::factory()->student()->create();

        $this->actingAs($yonetici)->from(route('admin.exam-reports.index', $ogrenci))
            ->post(route('admin.exam-reports.store', $ogrenci), ['title' => 'x', 'pdf' => UploadedFile::fake()->image('a.jpg')])
            ->assertRedirect(route('admin.exam-reports.index', $ogrenci))
            ->assertSessionHasErrors('pdf');

        $this->actingAs($ogrenci)
            ->post(route('admin.exam-reports.store', $ogrenci), ['title' => 'x', 'pdf' => $this->pdf()])
            ->assertForbidden();

        // Ogrenci olmayan birine rapor yuklenmez.
        $veli = User::factory()->parent()->create();
        $this->actingAs($yonetici)->get(route('admin.exam-reports.index', $veli))->assertNotFound();

        $this->assertSame(0, ExamReport::count());
    }

    public function test_a_failed_analysis_keeps_the_file_and_can_be_retried(): void
    {
        // Ilk cagri 502, ikincisi (yeniden analiz) gecerli cevap.
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push('patladi', 502)
            ->push(['choices' => [['message' => ['content' => json_encode($this->analiz())]]]])]);
        $yonetici = User::factory()->admin()->create();
        $ogrenci = User::factory()->student()->create();

        $this->actingAs($yonetici)
            ->post(route('admin.exam-reports.store', $ogrenci), ['title' => 'Deneme 1', 'pdf' => $this->pdf()])
            ->assertRedirect()->assertSessionHas('error');

        $rapor = ExamReport::sole();
        $this->assertSame(ExamReport::FAILED, $rapor->status);
        $this->assertStringContainsString('502', $rapor->error);
        Storage::disk('yukleme')->assertExists($rapor->file_path);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))->assertOk()->assertSee('Analiz yapılamadı');

        $this->actingAs($yonetici)->post(route('admin.exam-reports.analyze', $rapor))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(ExamReport::DONE, $rapor->fresh()->status);
        $this->assertNull($rapor->fresh()->error);
    }

    public function test_removing_a_report_deletes_the_file(): void
    {
        $rapor = ExamReport::factory()->analyzed()->create();
        Storage::disk('yukleme')->put($rapor->file_path, '%PDF');

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.exam-reports.destroy', $rapor))
            ->assertRedirect(route('admin.exam-reports.index', $rapor->student_id));

        Storage::disk('yukleme')->assertMissing($rapor->file_path);
        $this->assertSame(0, ExamReport::count());
    }
}
