<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\StudentParent;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 12 - Deneme sonucu ve siralamalar.
 *
 * Sonuclari YONETICI giriyor: sonuclar kuruma toplu geliyor, ogrenciden
 * girmesini beklemek hem gecikme hem hata kaynagi olurdu.
 *
 * Profile islenen her sey VELIYE ACIK (karar 2, 22 Eylul). Eski
 * can_view_exams bayragi bu yuzden hic planlanmadi.
 */
class ExamResultTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->withPackage(\App\Models\Package::factory()->tier3())->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function deneme(): ExamEvent
    {
        return ExamEvent::create([
            'title' => 'TYT Deneme 4',
            'exam_type' => 'tyt',
            'exam_date' => '2026-09-20',
        ]);
    }

    private function ders(string $ad = 'Matematik'): Subject
    {
        return Subject::create(['name' => $ad, 'exam_type' => 'tyt', 'sort_order' => 1]);
    }

    // --- Net hesabi ---------------------------------------------------------

    /**
     * Net = D - Y/4. Hesaplanir, SAKLANMAZ.
     *
     * Sutunda tutmak ikinci bir dogruluk kaynagi yaratir: dogru/yanlis
     * duzeltilip net guncellenmezse ikisi sessizce ayrisir ve hangisinin
     * dogru oldugu sorusu cevapsiz kalir. Ayni gerekce Dalga 2'de
     * study_tables.status icin de verilmisti.
     */
    public function test_the_net_is_four_wrongs_per_correct(): void
    {
        $satir = new \App\Models\ExamResultSubject(['correct' => 20, 'wrong' => 8, 'blank' => 12]);

        $this->assertSame(18.0, $satir->net);
    }

    public function test_a_perfect_section_has_no_penalty(): void
    {
        $satir = new \App\Models\ExamResultSubject(['correct' => 40, 'wrong' => 0, 'blank' => 0]);

        $this->assertSame(40.0, $satir->net);
    }

    public function test_the_net_can_go_negative(): void
    {
        $satir = new \App\Models\ExamResultSubject(['correct' => 1, 'wrong' => 8, 'blank' => 31]);

        $this->assertSame(-1.0, $satir->net);
    }

    // --- Sonuc girisi -------------------------------------------------------

    public function test_an_admin_records_a_result_with_rankings(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => 3,
                'total_institution' => 42,
                'rank_country' => 1240,
                'total_country' => 180000,
                'note' => 'Matematikte fen netleri düştü, tekrar gerekiyor.',
                'subjects' => [
                    $ders->id => ['correct' => 20, 'wrong' => 8, 'blank' => 12],
                ],
            ])
            ->assertRedirect();

        $sonuc = ExamResult::where('student_id', $ogrenci->id)->first();

        $this->assertNotNull($sonuc);
        $this->assertSame(3, $sonuc->rank_institution);
        $this->assertSame(42, $sonuc->total_institution);
        $this->assertSame(18.0, $sonuc->totalNet());
    }

    /**
     * Siralamalar SONRADAN aciklaniyor: kurum siralamasi ertesi gun, Turkiye
     * geneli bir hafta sonra gelebilir. Sonuc girisi eksik veriyle baslayip
     * tamamlanabilmeli.
     */
    public function test_rankings_may_be_left_empty(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => [$ders->id => ['correct' => 30, 'wrong' => 4, 'blank' => 6]],
            ])
            ->assertRedirect();

        $sonuc = ExamResult::where('student_id', $ogrenci->id)->first();

        $this->assertNull($sonuc->rank_institution);
        $this->assertSame(29.0, $sonuc->totalNet());
    }

    public function test_recording_again_updates_instead_of_duplicating(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        foreach ([['correct' => 10, 'wrong' => 0, 'blank' => 30], ['correct' => 25, 'wrong' => 4, 'blank' => 11]] as $girdi) {
            $this->actingAs($this->yonetici())
                ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                    'subjects' => [$ders->id => $girdi],
                ]);
        }

        $this->assertSame(1, ExamResult::where('student_id', $ogrenci->id)->count());
        $this->assertSame(24.0, ExamResult::where('student_id', $ogrenci->id)->first()->totalNet());
    }

    public function test_the_counts_must_be_whole_numbers(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => [$ders->id => ['correct' => -3, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertSessionHasErrors();
    }

    public function test_a_student_cannot_record_a_result(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($ogrenci)
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'subjects' => [$ders->id => ['correct' => 40, 'wrong' => 0, 'blank' => 0]],
            ])
            ->assertForbidden();
    }

    // --- Kim gorur ----------------------------------------------------------

    public function test_a_student_sees_their_own_result(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_institution' => 3,
                'total_institution' => 42,
                'note' => 'Fen netleri düştü.',
                'subjects' => [$ders->id => ['correct' => 20, 'wrong' => 8, 'blank' => 12]],
            ]);

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('TYT Deneme 4')
            ->assertSee('Fen netleri düştü.');
    }

    /**
     * Karar 2 (22 Eylul): profile islenen her sey veliye acik. Eskiden
     * deneme sonuclari veliye kapaliydi; akis degisti.
     */
    public function test_a_parent_sees_their_childs_result_and_note(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $ogrenci]), [
                'rank_country' => 1240,
                'total_country' => 180000,
                'note' => 'Matematik iyi, Türkçe zayıf.',
                'subjects' => [$ders->id => ['correct' => 20, 'wrong' => 8, 'blank' => 12]],
            ]);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Matematik iyi, Türkçe zayıf.');
    }

    public function test_a_parent_cannot_see_another_students_result(): void
    {
        $baskasi = $this->ogrenci('Başkası');
        $veli = User::factory()->parent()->create();

        $deneme = $this->deneme();
        $ders = $this->ders();

        $this->actingAs($this->yonetici())
            ->post(route('admin.exam-results.store', [$deneme, $baskasi]), [
                'subjects' => [$ders->id => ['correct' => 20, 'wrong' => 8, 'blank' => 12]],
            ]);

        $this->actingAs($veli)->get(route('parent.student', $baskasi))->assertForbidden();
    }
}
