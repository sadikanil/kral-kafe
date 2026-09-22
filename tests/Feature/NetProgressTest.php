<?php

namespace Tests\Feature;

use App\Enums\ExamType;
use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\StudentParent;
use App\Models\Subject;
use App\Models\User;
use App\Support\ChartPath;
use App\Support\NetProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 12b - Net gelisim grafigi.
 *
 * SUNUCUDA URETILEN SATIR ICI SVG. Proje derleme adimi tasimiyor (Vite yok,
 * stiller elle yazili public/css/app.css'te); bir grafik kutuphanesini
 * CDN'den cekmek bu projeye yeni bir bagimlilik sinifi sokardi. SVG'yi
 * sunucuda uretmek ayrica TEST EDILEBILIR kiliyor.
 *
 * DENEME TURUNE GORE AYRI grafik: TYT ile AYT'nin ders listesi ve net
 * araligi farkli, ayni eksene koymak iki seriyi de okunamaz yapardi.
 *
 * NEDENSELLIK IDDIASI YOK (SS7-C): calisma suresi bu grafige bindirilmiyor.
 */
class NetProgressTest extends TestCase
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

    private function ders(string $ad): Subject
    {
        return Subject::firstOrCreate(
            ['name' => $ad],
            ['exam_type' => 'tyt', 'sort_order' => 1],
        );
    }

    /**
     * @param  array<string,array{0:int,1:int}>  $dersler  ad => [dogru, yanlis]
     */
    private function sonuc(User $ogrenci, string $baslik, string $gun, array $dersler, string $tur = 'tyt'): ExamResult
    {
        $olay = ExamEvent::create([
            'title' => $baslik,
            'exam_type' => $tur,
            'exam_date' => $gun,
        ]);

        $sonuc = ExamResult::create([
            'exam_event_id' => $olay->id,
            'student_id' => $ogrenci->id,
        ]);

        foreach ($dersler as $ad => [$dogru, $yanlis]) {
            ExamResultSubject::create([
                'exam_result_id' => $sonuc->id,
                'subject_id' => $this->ders($ad)->id,
                'correct' => $dogru,
                'wrong' => $yanlis,
                'blank' => 0,
            ]);
        }

        return $sonuc->fresh();
    }

    private function seriler(User $ogrenci): array
    {
        return NetProgress::fromResults(
            ExamResult::where('student_id', $ogrenci->id)->with(['event', 'subjects.subject'])->get()
        );
    }

    // --- Seri kurulumu -------------------------------------------------------

    /**
     * TEK deneme grafik URETMEZ.
     *
     * Bir nokta bir "gelisim" degil; tek noktali bir cizgi, olmayan bir
     * egilim varmis izlenimi verir.
     */
    public function test_a_single_exam_draws_no_chart(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);

        $this->assertSame([], $this->seriler($ogrenci));
    }

    public function test_two_exams_produce_a_chart(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4]]);

        $grafikler = $this->seriler($ogrenci);

        $this->assertCount(1, $grafikler);
        $this->assertSame(ExamType::Tyt, $grafikler[0]['type']);
    }

    /** Noktalar ESKIDEN YENIYE; liste ekranda yeniden eskiye siralanıyor. */
    public function test_the_points_run_oldest_to_newest(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4]]);
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);

        $grafik = $this->seriler($ogrenci)[0];

        $this->assertSame(['TYT 1', 'TYT 2'], $grafik['labels']);
    }

    /**
     * TYT ve AYT AYRI grafik.
     *
     * Ders listeleri ve net araliklari farkli; ayni eksene koymak iki
     * seriyi de okunamaz yapardi.
     */
    public function test_each_exam_type_gets_its_own_chart(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4]]);
        $this->sonuc($ogrenci, 'AYT 1', '2026-09-06', ['Fizik' => [10, 4]], 'ayt');
        $this->sonuc($ogrenci, 'AYT 2', '2026-09-13', ['Fizik' => [12, 0]], 'ayt');

        $turler = array_map(fn ($g) => $g['type'], $this->seriler($ogrenci));

        $this->assertContains(ExamType::Tyt, $turler);
        $this->assertContains(ExamType::Ayt, $turler);
    }

    public function test_the_total_net_is_always_the_first_series(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8], 'Türkçe' => [30, 4]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4], 'Türkçe' => [35, 0]]);

        $grafik = $this->seriler($ogrenci)[0];

        $this->assertSame('Toplam', $grafik['series'][0]['name']);
        // 18 + 29 = 47, sonra 29 + 35 = 64
        $this->assertSame([47.0, 64.0], $grafik['series'][0]['points']);
    }

    public function test_every_subject_gets_its_own_series(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8], 'Türkçe' => [30, 4]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4], 'Türkçe' => [35, 0]]);

        $adlar = array_map(fn ($s) => $s['name'], $this->seriler($ogrenci)[0]['series']);

        $this->assertSame(['Toplam', 'Matematik', 'Türkçe'], $adlar);
    }

    /**
     * Bir denemede OLMAYAN ders o noktada bosluk birakir.
     *
     * Sifir yazmak "sifir cekti" demek olurdu - girilmemis veriyle kotu
     * sonucu ayirt edememek, grafigi yalanci yapar.
     */
    public function test_a_subject_missing_from_one_exam_leaves_a_gap(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4], 'Fizik' => [10, 0]]);

        $seriler = collect($this->seriler($ogrenci)[0]['series'])->keyBy('name');

        $this->assertSame([null, 10.0], $seriler['Fizik']['points']);
    }

    public function test_no_results_produce_no_charts(): void
    {
        $this->assertSame([], $this->seriler($this->ogrenci()));
    }

    // --- SVG koordinatlari ---------------------------------------------------

    public function test_the_highest_value_sits_at_the_top(): void
    {
        // y ekseni yukaridan asagi: en yuksek deger y=0'a yakin.
        $nokta = ChartPath::points([10.0, 20.0], 10.0, 20.0, 100, 50);

        $this->assertSame('0,50 100,0', $nokta);
    }

    /** Duz seri ORTADA durur; sifira bolme yok. */
    public function test_a_flat_series_sits_in_the_middle(): void
    {
        $this->assertSame('0,25 100,25', ChartPath::points([15.0, 15.0], 15.0, 15.0, 100, 50));
    }

    /** Bosluklar atlanir - cizgi var olan noktalari birlestirir. */
    public function test_gaps_are_skipped(): void
    {
        $this->assertSame('0,50 100,0', ChartPath::points([10.0, null, 20.0], 10.0, 20.0, 100, 50));
    }

    public function test_a_series_with_no_values_produces_nothing(): void
    {
        $this->assertSame('', ChartPath::points([null, null], 0.0, 10.0, 100, 50));
    }

    public function test_negative_nets_are_placed_correctly(): void
    {
        // -5 en dusuk, 15 en yuksek: -5 tabanda, 5 ortada.
        $this->assertSame('0,50 50,25 100,0', ChartPath::points([-5.0, 5.0, 15.0], -5.0, 15.0, 100, 50));
    }

    // --- Ekranda -------------------------------------------------------------

    public function test_a_student_sees_the_chart(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4]]);

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Net gelişimi')
            ->assertSee('<svg', false);
    }

    public function test_a_parent_sees_the_chart_too(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);
        $this->sonuc($ogrenci, 'TYT 2', '2026-09-12', ['Matematik' => [30, 4]]);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Net gelişimi');
    }

    public function test_one_exam_draws_no_chart_on_the_page(): void
    {
        $ogrenci = $this->ogrenci();
        $this->sonuc($ogrenci, 'TYT 1', '2026-09-05', ['Matematik' => [20, 8]]);

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('TYT 1')
            ->assertDontSee('Net gelişimi');
    }
}
