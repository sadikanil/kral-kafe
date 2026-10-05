<?php

namespace Tests\Feature;

use App\Contracts\ExamPdfReader;
use App\Models\ExamEvent;
use App\Models\ExamImport;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sonuc girerken serbest deneme (5 Ekim 2026).
 *
 * Serbest denemeler akis icinde cozuluyor: hem kurum PDF'i yuklerken hem
 * ogrenciye tek tek sonuc girerken daha once eklenen serbest denemeler
 * (penceresi kapanmis olanlar dahil) ayri grupta secilir, yoksa ayni
 * formda yenisi olusturulur.
 */
class SpontaneousExamTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;
    private User $ogrenci;
    private ExamEvent $takvimdeki;
    private ExamEvent $serbest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', config('kafe.timezone')));
        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');
        // Okuma bu testlerde hic baslamiyor; yalnizca bagimlilik cozulsun.
        $this->app->instance(ExamPdfReader::class, $this->createStub(ExamPdfReader::class));

        $this->yonetici = User::factory()->admin()->create();
        $this->ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create(['name' => 'Elif Yıldırım']);
        $this->takvimdeki = ExamEvent::create(['title' => 'Hız ve Renk TYT 2', 'exam_type' => 'tyt', 'exam_date' => '2026-09-27']);
        // Penceresi kapanmis serbest deneme: sonucu sonradan gelebilir.
        $this->serbest = ExamEvent::create(['title' => 'Limit AYT Serbest', 'exam_type' => 'ayt', 'exam_date' => '2026-09-01',
            'available_until' => '2026-09-30', 'is_flexible' => true]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('kurum.pdf', "%PDF-1.7\n% sahte\n");
    }

    public function test_the_import_form_lists_flexible_exams_separately_and_offers_a_new_one(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.exam-imports.index'))
            ->assertOk()
            ->assertSee('+ Yeni serbest deneme oluştur')
            ->assertSeeInOrder(['<optgroup label="Serbest denemeler">', '1–30 Eylül · Limit AYT Serbest (AYT)',
                '<optgroup label="Takvimdeki denemeler">', 'Hız ve Renk TYT 2'], false)
            ->assertSee('Çözüldüğü gün');
    }

    public function test_an_import_can_pick_a_flexible_exam(): void
    {
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->serbest->id,
            'pdf' => $this->pdf(),
        ])->assertRedirect();

        $this->assertSame($this->serbest->id, ExamImport::sole()->exam_event_id);
    }

    public function test_an_import_can_create_a_new_flexible_exam(): void
    {
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => 'yeni',
            'new_title' => '345 TYT 4',
            'new_exam_type' => 'tyt',
            'new_exam_date' => '2026-10-04',
            'pdf' => $this->pdf(),
        ])->assertRedirect();

        $deneme = ExamEvent::where('title', '345 TYT 4')->sole();
        $this->assertTrue($deneme->is_flexible);
        $this->assertSame('2026-10-04', $deneme->exam_date->toDateString());
        $this->assertSame('2026-10-04', $deneme->available_until->toDateString());
        $this->assertSame($this->yonetici->id, $deneme->created_by);
        $this->assertSame($deneme->id, ExamImport::sole()->exam_event_id);

        // Tek gunluk pencere gecti: ogrencinin "serbest denemeler" kutusunda durmaz.
        $this->assertFalse(ExamEvent::flexibleOpen()->whereKey($deneme->id)->exists());
    }

    public function test_a_new_flexible_exam_needs_a_name_and_a_past_day(): void
    {
        $this->actingAs($this->yonetici)->from(route('admin.exam-imports.index'))->post(route('admin.exam-imports.store'), [
            'exam_event_id' => 'yeni',
            'new_title' => '',
            'new_exam_type' => 'lgs',
            'new_exam_date' => '2026-10-06',
            'pdf' => $this->pdf(),
        ])->assertRedirect(route('admin.exam-imports.index'))
            ->assertSessionHasErrors(['new_title', 'new_exam_type', 'new_exam_date' => 'Çözüldüğü gün bugünden sonra olamaz.']);

        $this->assertSame(2, ExamEvent::count());
        $this->assertSame(0, ExamImport::count());
    }

    public function test_new_fields_are_ignored_when_an_existing_exam_is_picked(): void
    {
        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $this->takvimdeki->id,
            'new_title' => '',
            'new_exam_date' => 'bozuk',
            'pdf' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, ExamEvent::count());
    }

    public function test_a_storage_failure_does_not_leave_an_orphan_exam(): void
    {
        config(['filesystems.disks.bozuk' => ['driver' => 'local', 'root' => '/proc/kral-kafe-yok', 'throw' => false],
            'filesystems.uploads' => 'bozuk']);

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => 'yeni', 'new_title' => '345 TYT 4', 'new_exam_type' => 'tyt', 'new_exam_date' => '2026-10-04',
            'pdf' => $this->pdf(),
        ])->assertSessionHas('error');

        $this->assertSame(0, ExamEvent::where('title', '345 TYT 4')->count());
    }

    public function test_an_import_only_accepts_tyt_ayt_exams(): void
    {
        $lgs = ExamEvent::create(['title' => 'LGS Deneme', 'exam_type' => 'lgs', 'exam_date' => '2026-09-20']);

        $this->actingAs($this->yonetici)->post(route('admin.exam-imports.store'), [
            'exam_event_id' => $lgs->id, 'pdf' => $this->pdf(),
        ])->assertSessionHasErrors('exam_event_id');
    }

    // --- Ogrenciye tek tek sonuc ---------------------------------------------

    public function test_the_student_page_offers_flexible_exams_and_a_new_one(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.exam-reports.index', $this->ogrenci))
            ->assertOk()
            ->assertSee(route('admin.exam-results.start', $this->ogrenci), false)
            ->assertSeeInOrder(['Serbest denemeler', 'Limit AYT Serbest', 'Takvimdeki denemeler', 'Hız ve Renk TYT 2']);
    }

    public function test_picking_an_exam_opens_its_result_form(): void
    {
        $this->actingAs($this->yonetici)->post(route('admin.exam-results.start', $this->ogrenci), [
            'exam_event_id' => $this->serbest->id,
        ])->assertRedirect(route('admin.exam-results.edit', [$this->serbest, $this->ogrenci]));
    }

    public function test_a_new_flexible_exam_opens_its_result_form(): void
    {
        $yanit = $this->actingAs($this->yonetici)->post(route('admin.exam-results.start', $this->ogrenci), [
            'exam_event_id' => 'yeni', 'new_title' => 'Bilgi Sarmal AYT 2', 'new_exam_type' => 'ayt', 'new_exam_date' => '2026-10-05',
        ]);

        $deneme = ExamEvent::where('title', 'Bilgi Sarmal AYT 2')->sole();
        $this->assertTrue($deneme->is_flexible);
        $yanit->assertRedirect(route('admin.exam-results.edit', [$deneme, $this->ogrenci]))
            ->assertSessionHas('success', 'Bilgi Sarmal AYT 2 serbest deneme olarak eklendi; sonucu girin.');

        $this->actingAs($this->yonetici)->get(route('admin.exam-results.edit', [$deneme, $this->ogrenci]))->assertOk();
    }

    public function test_only_students_and_admins(): void
    {
        $veli = User::factory()->parent()->create();

        $this->actingAs($this->yonetici)->post(route('admin.exam-results.start', $veli), [
            'exam_event_id' => $this->serbest->id,
        ])->assertNotFound();

        $this->actingAs($this->ogrenci)->post(route('admin.exam-results.start', $this->ogrenci), [
            'exam_event_id' => 'yeni', 'new_title' => 'X', 'new_exam_type' => 'tyt', 'new_exam_date' => '2026-10-05',
        ])->assertForbidden();
        $this->assertSame(0, ExamEvent::where('title', 'X')->count());
    }
}
