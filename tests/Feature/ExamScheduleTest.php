<?php

namespace Tests\Feature;

use App\Enums\ExamType;
use App\Models\ExamEvent;
use App\Models\User;
use App\Support\ExamCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Deneme sinavi takvimi: yonetici planlar, ogrenci ve veli takvimde gorur,
 * paneller "siradaki deneme, N gun kaldi" diye hatirlatir.
 *
 * Takvim KAFE GENELI ve sonuc tutmaz; ogrenci basina sonuc FEATURE 6.
 */
class ExamScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function bugun(string $yerelAn = '2026-09-20 15:00'): void
    {
        $this->travelTo(Carbon::parse($yerelAn, config('kafe.timezone')));
    }

    private function deneme(string $tarih, array $ek = []): ExamEvent
    {
        return ExamEvent::factory()->create(array_merge([
            'title' => 'Genel Deneme',
            'exam_type' => ExamType::Tyt->value,
            'exam_date' => $tarih,
            'starts_at' => '10:00',
        ], $ek));
    }

    // --- Model ---------------------------------------------------------------

    public function test_countdown_is_computed_on_the_cafe_day(): void
    {
        // UTC'de hala 26 Eylul 21:30 ama Istanbul'da 27 Eylul 00:30: deneme BUGUN.
        $this->travelTo(Carbon::parse('2026-09-26 21:30', 'UTC'));
        $deneme = $this->deneme('2026-09-27');

        $this->assertSame(0, $deneme->daysUntil());
        $this->assertSame('Bugün', $deneme->countdownLabel());
    }

    public function test_countdown_labels(): void
    {
        $this->bugun();

        $this->assertSame('Yarın', $this->deneme('2026-09-21')->countdownLabel());
        $this->assertSame('7 gün kaldı', $this->deneme('2026-09-27')->countdownLabel());
        $this->assertSame('Geçti', $this->deneme('2026-09-19')->countdownLabel());
        $this->assertSame('27 Eylül Pazar', $this->deneme('2026-09-27')->dateLabel());
    }

    public function test_upcoming_and_past_scopes_split_on_the_cafe_day(): void
    {
        $this->bugun();
        $gecmis = $this->deneme('2026-09-19');
        $bugunku = $this->deneme('2026-09-20');
        $gelecek = $this->deneme('2026-10-05');

        $this->assertSame([$bugunku->id, $gelecek->id], ExamEvent::upcoming()->pluck('id')->all());
        $this->assertSame([$gecmis->id], ExamEvent::past()->pluck('id')->all());
        $this->assertSame([$gecmis->id, $bugunku->id], ExamEvent::inMonth(2026, 9)->pluck('id')->all());
    }

    public function test_the_calendar_grid_covers_whole_weeks_from_monday(): void
    {
        $this->bugun();
        $haftalar = ExamCalendar::weeks(2026, 9, ExamEvent::inMonth(2026, 9)->get());

        // Eylul 2026: 1'i sali, 30'u carsamba -> 31 Agustos pazartesi ... 4 Ekim pazar
        $this->assertSame('2026-08-31', $haftalar[0][0]['date']);
        $this->assertSame('2026-10-04', end($haftalar)[6]['date']);
        $this->assertFalse($haftalar[0][0]['inMonth']);
        $this->assertTrue($haftalar[2][6]['isToday']);   // 20 Eylul pazar (3. hafta)
    }

    public function test_a_broken_month_parameter_falls_back_to_the_current_month(): void
    {
        $this->bugun();

        $this->assertSame([2026, 9], ExamCalendar::parseMonth(null));
        $this->assertSame([2026, 9], ExamCalendar::parseMonth('saçma'));
        $this->assertSame([2026, 9], ExamCalendar::parseMonth('2026-13'));
        $this->assertSame([2027, 1], ExamCalendar::parseMonth('2027-01'));
    }

    // --- Yonetici -------------------------------------------------------------

    public function test_an_admin_plans_edits_and_removes_an_exam(): void
    {
        $this->bugun();
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->get('/yonetim/denemeler')->assertOk()->assertSee('Planlanmış deneme yok');

        $this->actingAs($yonetici)->post('/yonetim/denemeler', [
            'title' => 'Türkiye Geneli 3',
            'exam_type' => 'tyt_ayt',
            'exam_date' => '2026-09-27',
            'starts_at' => '09:30',
            'note' => 'Optik form getirin',
        ])->assertRedirect(route('admin.exams.index'));

        $deneme = ExamEvent::sole();
        $this->assertSame(ExamType::TytAyt, $deneme->exam_type);
        $this->assertSame($yonetici->id, $deneme->created_by);

        $this->actingAs($yonetici)->get('/yonetim/denemeler')
            ->assertOk()
            ->assertSee('Türkiye Geneli 3')
            ->assertSee('27 Eylül Pazar')
            ->assertSee('7 gün kaldı');

        $this->actingAs($yonetici)->put(route('admin.exams.update', $deneme), [
            'title' => 'Türkiye Geneli 3',
            'exam_type' => 'tyt',
            'exam_date' => '2026-09-28',
        ])->assertRedirect();

        $this->assertSame('2026-09-28', $deneme->fresh()->exam_date->toDateString());
        $this->assertNull($deneme->fresh()->starts_at);

        $this->actingAs($yonetici)->delete(route('admin.exams.destroy', $deneme))->assertRedirect();
        $this->assertSame(0, ExamEvent::count());
    }

    public function test_the_form_rejects_a_bad_type_date_or_time(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.exams.create'))
            ->post('/yonetim/denemeler', [
                'title' => 'X',
                'exam_type' => 'kpss',
                'exam_date' => '27.09.2026',
                'starts_at' => '9',
            ])
            ->assertRedirect(route('admin.exams.create'))
            ->assertSessionHasErrors(['exam_type', 'exam_date', 'starts_at']);

        $this->assertSame(0, ExamEvent::count());
    }

    public function test_only_an_admin_can_change_the_schedule(): void
    {
        $veri = ['title' => 'X', 'exam_type' => 'tyt', 'exam_date' => '2026-09-27'];

        // Misafir ONCE: actingAs ayni test icinde oturumu acik birakir.
        $this->post('/yonetim/denemeler', $veri)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())->post('/yonetim/denemeler', $veri)->assertForbidden();
        $this->actingAs(User::factory()->parent()->create())->post('/yonetim/denemeler', $veri)->assertForbidden();

        $this->assertSame(0, ExamEvent::count());
    }

    // --- Ogrenci ve veli -----------------------------------------------------

    public function test_students_and_parents_see_the_calendar_read_only(): void
    {
        $this->bugun();
        $this->deneme('2026-09-27', ['title' => 'Eylül Denemesi']);
        $this->deneme('2026-10-10', ['title' => 'Ekim Denemesi']);

        $ogrenci = User::factory()->student()->withPackage(\App\Models\Package::factory()->tier3())->create();
        $veli = User::factory()->parent()->create();
        // Veli cocugunun paketini gorur (Dalga 19): deneme adlari kulupte.
        $veli->students()->attach($ogrenci);

        $this->get('/kullanici/denemeler')->assertRedirect(route('login'));

        $this->actingAs($ogrenci)->get('/kullanici/denemeler')
            ->assertOk()
            ->assertSee('Eylül 2026')
            ->assertSee('Eylül Denemesi')
            ->assertSee('Ekim Denemesi')       // yaklasanlar listesi ay bagimsiz
            ->assertDontSee('Düzenle');

        $this->actingAs($ogrenci)->get('/kullanici/denemeler?ay=2026-10')
            ->assertOk()
            ->assertSee('Ekim 2026');

        $this->actingAs($veli)->get('/veli/denemeler')
            ->assertOk()
            ->assertSee('Eylül Denemesi')
            ->assertSee('Çocuklarım');          // veli iskeleti

        $this->actingAs($ogrenci)->get('/veli/denemeler')->assertForbidden();
    }

    public function test_the_dashboards_remind_the_next_exam(): void
    {
        $this->bugun();
        $this->deneme('2026-09-19', ['title' => 'Geçmiş Deneme']);
        $this->deneme('2026-09-27', ['title' => 'Yakın Deneme']);
        $this->deneme('2026-10-15', ['title' => 'Uzak Deneme']);

        $ogrenci = User::factory()->student()->withPackage(\App\Models\Package::factory()->tier3())->create();
        $veli = User::factory()->parent()->create();
        // Veli cocugunun paketini gorur (Dalga 19): deneme adlari kulupte.
        $veli->students()->attach($ogrenci);

        $this->actingAs($ogrenci)->get('/kullanici/panel')
            ->assertOk()
            ->assertSee('Sıradaki deneme:')
            ->assertSee('Yakın Deneme')
            ->assertSee('7 gün kaldı')
            ->assertSee('alert-warning')
            ->assertSee('Uzak Deneme')
            ->assertDontSee('Geçmiş Deneme');

        $this->actingAs($veli)->get('/veli')
            ->assertOk()
            ->assertSee('Yakın Deneme')
            ->assertSee(route('parent.exams'));
    }

    public function test_a_distant_exam_is_informational_and_no_exam_shows_nothing(): void
    {
        $this->bugun();
        $ogrenci = User::factory()->student()->withPackage(\App\Models\Package::factory()->tier3())->create();

        $this->actingAs($ogrenci)->get('/kullanici/panel')->assertOk()->assertDontSee('Sıradaki deneme');

        $this->deneme('2026-10-15', ['title' => 'Uzak Deneme']);

        $this->actingAs($ogrenci)->get('/kullanici/panel')
            ->assertOk()
            ->assertSee('Uzak Deneme')
            ->assertSee('alert-info')
            ->assertDontSee('alert-warning');
    }
}
