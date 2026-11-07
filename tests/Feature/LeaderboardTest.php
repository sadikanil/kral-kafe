<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\Location;
use App\Models\Product;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\User;
use App\Services\Leaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Liderlik tablosu (7 Kasim 2026). Puan saklanmaz, onayli veriden hesaplanir:
 * calisma (10 dk = 1), deneme (katilim 20 + basari <=30 + gelisim 10),
 * adisyon (25 TL = 1, gunde en fazla 4).
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private StudyTable $masa;

    private Product $tost;

    protected function setUp(): void
    {
        parent::setUp();

        // Carsamba; hafta 2-8 Kasim, ay Kasim.
        $this->travelTo(Carbon::parse('2026-11-04 18:00', config('kafe.timezone')));
        $this->masa = StudyTable::create(['name' => 'Masa 1']);
        $this->tost = Product::create(['name' => 'Tost', 'unit_price' => 50, 'unit_type' => 'adet', 'is_active' => true]);
    }

    private function uye(string $ad, array $ek = []): User
    {
        return User::factory()->student()->create(['name' => $ad] + $ek);
    }

    private function calisma(User $uye, string $bas, int $dakika, ApprovalStatus $durum = ApprovalStatus::Approved): StudySession
    {
        $basla = Carbon::parse($bas, config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $uye->id,
            'study_table_id' => $this->masa->id,
            'started_at' => $basla,
            'ended_at' => $basla->copy()->addMinutes($dakika),
            'duration_minutes' => $dakika,
            'approval_status' => $durum->value,
        ]);
    }

    private function siparis(User $uye, string $zaman, int $adet, int $kapsanan = 0, bool $geriAlindi = false): Consumption
    {
        return Consumption::create([
            'user_id' => $uye->id, 'product_id' => $this->tost->id, 'location_id' => Location::selfService()->id,
            'quantity' => $adet, 'covered_quantity' => $kapsanan, 'unit_price' => 50,
            'consumed_at' => Carbon::parse($zaman, config('kafe.timezone')), 'is_undone' => $geriAlindi,
        ]);
    }

    /** @param array<int,array{0:int,1:int,2:int}> $dersler dogru, yanlis, bos */
    private function deneme(User $uye, string $tarih, array $dersler, string $tur = 'tyt'): ExamResult
    {
        $etkinlik = ExamEvent::create(['title' => 'Deneme ' . uniqid(), 'exam_type' => $tur, 'exam_date' => $tarih]);
        $sonuc = ExamResult::create(['exam_event_id' => $etkinlik->id, 'student_id' => $uye->id]);

        foreach ($dersler as $i => [$d, $y, $b]) {
            $ders = Subject::create(['name' => 'Ders ' . uniqid(), 'exam_type' => $tur, 'sort_order' => $i]);
            ExamResultSubject::create(['exam_result_id' => $sonuc->id, 'subject_id' => $ders->id, 'correct' => $d, 'wrong' => $y, 'blank' => $b]);
        }

        return $sonuc;
    }

    private function hafta(): \Illuminate\Support\Collection
    {
        $p = Leaderboard::pencere('hafta', null);

        return app(Leaderboard::class)->satirlar($p['from'], $p['to']);
    }

    // --- Puan hesabi -------------------------------------------------------------

    public function test_only_approved_study_counts_ten_minutes_per_point(): void
    {
        $ali = $this->uye('Ali Çalışkan');
        $this->calisma($ali, '2026-11-03 09:00', 125);
        $this->calisma($ali, '2026-11-03 14:00', 60, ApprovalStatus::Pending);
        $this->calisma($ali, '2026-11-01 09:00', 300); // gecen hafta (pazar)

        $satir = $this->hafta()->firstWhere('user.id', $ali->id);

        $this->assertSame(125, $satir['dakika']);
        $this->assertSame(12, $satir['calisma']);
        $this->assertSame(12, $satir['toplam']);
    }

    public function test_exam_points_reward_taking_part_net_and_improvement(): void
    {
        $ece = $this->uye('Ece Denemeci');
        // Onceki TYT (gecen ay): 20 net / 40 soru.
        $this->deneme($ece, '2026-10-20', [[20, 0, 20]]);
        // Bu hafta: 30 dogru 8 yanlis 2 bos = 28 net / 40 soru -> 21 basari; gelisim +10.
        $this->deneme($ece, '2026-11-03', [[30, 8, 2]]);

        $satir = $this->hafta()->firstWhere('user.id', $ece->id);

        $this->assertSame(1, $satir['deneme_sayisi']);
        $this->assertSame(20 + 21 + 10, $satir['deneme']);
    }

    public function test_no_improvement_bonus_against_another_exam_type_or_a_better_past(): void
    {
        $can = $this->uye('Can Ayt');
        $this->deneme($can, '2026-10-20', [[30, 0, 10]], 'tyt');
        $this->deneme($can, '2026-11-02', [[10, 0, 30]], 'ayt'); // ilk AYT: gelisim yok
        $this->deneme($can, '2026-11-04', [[20, 0, 20]], 'tyt'); // onceki TYT'den dusuk

        $satir = $this->hafta()->firstWhere('user.id', $can->id);

        // AYT: 20 + round(10/40*30)=8 ; TYT: 20 + 15
        $this->assertSame(2, $satir['deneme_sayisi']);
        $this->assertSame(28 + 35, $satir['deneme']);
    }

    public function test_purchases_give_a_small_capped_point_and_skip_undone_and_package_items(): void
    {
        $sude = $this->uye('Sude Alıcı');
        $this->siparis($sude, '2026-11-03 10:00', 1);            // 50 TL
        $this->siparis($sude, '2026-11-03 12:00', 1, 1);         // pakete dahil: 0 TL
        $this->siparis($sude, '2026-11-03 13:00', 2, 0, true);   // geri alindi
        $this->siparis($sude, '2026-11-04 10:00', 6);            // 300 TL -> 12, tavan 4

        $satir = $this->hafta()->firstWhere('user.id', $sude->id);

        $this->assertSame(2 + 4, $satir['alisveris']);
    }

    public function test_ranking_breaks_ties_by_study_time_and_hides_zero_and_suspended(): void
    {
        $a = $this->uye('Ayşe Kaya');
        $b = $this->uye('Burak Demir');
        $c = $this->uye('Cem Sıfır');
        $d = $this->uye('Deniz Askıda', ['subscription_status' => 'suspended']);
        $this->calisma($a, '2026-11-03 09:00', 100);   // 10 puan, 100 dk
        $this->calisma($b, '2026-11-03 09:00', 60);    // 6 puan
        $this->siparis($b, '2026-11-03 10:00', 2);     // +4 -> 10 puan, 60 dk
        $this->calisma($d, '2026-11-03 09:00', 600);

        $satirlar = $this->hafta();

        $this->assertSame([$a->id, $b->id], $satirlar->pluck('user.id')->all());
        $this->assertSame([1, 2], $satirlar->pluck('sira')->all());
        $this->assertNull($satirlar->firstWhere('user.id', $c->id));
    }

    public function test_the_month_window_and_bad_dates(): void
    {
        $ay = Leaderboard::pencere('ay', '2026-10-15');
        $this->assertSame('2026-10-01', $ay['baslangic']);
        $this->assertSame('Ekim 2026', $ay['etiket']);
        $this->assertSame('2026-11-01', $ay['sonraki']);

        $bu = Leaderboard::pencere('ay', null);
        $this->assertSame('2026-11-01', $bu['baslangic']);
        $this->assertNull($bu['sonraki'], 'gelecek ay yok');

        $bozuk = Leaderboard::pencere('yil', 'abc');
        $this->assertSame('hafta', $bozuk['donem']);
        $this->assertSame('2026-11-02', $bozuk['baslangic']);
        $this->assertSame('2026-10-26', $bozuk['onceki']);
    }

    public function test_short_names(): void
    {
        $this->assertSame('Ayşe K.', Leaderboard::kisaAd('Ayşe Kaya'));
        $this->assertSame('Mehmet Ş.', Leaderboard::kisaAd('Mehmet Ali Şahin'));
        $this->assertSame('Elif', Leaderboard::kisaAd('Elif'));
        $this->assertSame('Ali V.', Leaderboard::kisaAd('  Ali   Veli  '));
        // Turkce buyuk harf: i -> İ, ı -> I.
        $this->assertSame('Zeynep İ.', Leaderboard::kisaAd('Zeynep irmak'));
        $this->assertSame('Can I.', Leaderboard::kisaAd('Can ışık'));
    }

    // --- Sayfa -------------------------------------------------------------------

    public function test_a_member_sees_the_podium_short_names_and_only_their_own_breakdown(): void
    {
        $uyeler = [];
        foreach (['Ayşe Kaya' => 200, 'Burak Demir' => 150, 'Cem Yılmaz' => 120, 'Deniz Ak' => 90, 'Ece Su' => 40] as $ad => $dk) {
            $uyeler[$ad] = $this->uye($ad);
            $this->calisma($uyeler[$ad], '2026-11-03 09:00', $dk);
        }

        $this->actingAs($uyeler['Deniz Ak'])->get(route('user.leaderboard'))
            ->assertOk()
            ->assertSee('Haftalık')
            ->assertSee('Aylık')
            ->assertSee('class="podium"', false)
            ->assertSeeInOrder(['podium-1', 'Ayşe K.', '20 puan', 'podium-2', 'Burak D.', '15 puan', 'podium-3', 'Cem Y.', '12 puan'], false)
            ->assertSeeInOrder(['leader-row', '4', 'Deniz Ak', 'Sen', '9', 'leader-row', '5', 'Ece S.', '4'], false)
            ->assertSee('4. sıradasın')
            ->assertDontSee('Ayşe Kaya')
            ->assertDontSee('çalışma · ', false);
    }

    public function test_the_admin_sees_full_names_and_the_breakdown(): void
    {
        $ayse = $this->uye('Ayşe Kaya');
        $this->calisma($ayse, '2026-11-03 09:00', 100);
        $this->siparis($ayse, '2026-11-03 10:00', 1);

        $this->actingAs(User::factory()->admin()->create())->get(route('user.leaderboard', ['donem' => 'ay']))
            ->assertOk()
            ->assertSee('Kasım 2026')
            ->assertSee('Ayşe Kaya')
            ->assertSee('10 çalışma · 0 deneme · 2 adisyon')
            ->assertDontSee('Senin puanın');
    }

    public function test_an_empty_week_and_a_member_without_points(): void
    {
        $this->actingAs($this->uye('Yeni Üye'))->get(route('user.leaderboard', ['tarih' => '2026-10-05']))
            ->assertOk()
            ->assertSee('Bu hafta henüz puan yok')
            ->assertSee('henüz puanın yok')
            ->assertSee('donem=hafta&amp;tarih=2026-10-12', false);
    }

    public function test_parents_coaches_staff_and_guests_cannot_open_it(): void
    {
        foreach ([Role::Parent, Role::Coach, Role::Staff, Role::Teacher] as $rol) {
            $this->actingAs(User::factory()->create(['role' => $rol->value]))->get(route('user.leaderboard'))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('user.leaderboard'))->assertRedirect(route('login'));
    }

    public function test_members_and_admins_find_it_in_the_menu(): void
    {
        $this->actingAs($this->uye('Menü Bakan'))->get(route('user.dashboard'))
            ->assertSee('href="' . route('user.leaderboard') . '"', false);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertSee('href="' . route('user.leaderboard') . '"', false);
    }
}
