<?php

namespace Tests\Feature;

use App\Enums\CommitmentKind;
use App\Models\Package;
use App\Models\StudentCommitment;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UX turu (23 Eyl): ogrenci paneli "calisma once". Ustte oturum ya da
 * baslat dugmesi, sonra BUGUNUN plani; para kartlari Adisyon'da.
 * Gun: 29 Eylul 2026 Sali.
 */
class StudentPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 12:00', config('kafe.timezone')));
    }

    private function ogrenci(?Package $paket = null): User
    {
        return User::factory()->student()
            ->withPackage($paket ?? Package::factory()->tier1()->create())
            ->create();
    }

    private function madde(User $ogrenci, string $baslik, string $gun): StudyPlanItem
    {
        return StudyPlanItem::create([
            'student_id' => $ogrenci->id, 'title' => $baslik, 'plan_date' => $gun,
            'week_start' => '2026-09-28', 'period' => 'week',
        ]);
    }

    public function test_the_panel_shows_todays_plan_only(): void
    {
        $ogrenci = $this->ogrenci();
        $this->madde($ogrenci, 'Bugünün işi', '2026-09-29');
        $this->madde($ogrenci, 'Yarının işi', '2026-09-30');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Bugünün işi')
            ->assertDontSee('Yarının işi');
    }

    public function test_the_panel_shows_todays_fixed_program(): void
    {
        $ogrenci = $this->ogrenci();
        StudentCommitment::create([
            'student_id' => $ogrenci->id, 'kind' => CommitmentKind::School->value,
            'weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '14:30',
        ]);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertSee('08:30–14:30');
    }

    public function test_money_is_on_the_tab_page_not_the_panel(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertDontSee('Bu Ay Toplam')
            ->assertDontSee('Son Tüketimlerim');
    }

    /** Raflardaki QR ile tuketim Dalga 8'de kalkti; ipucu ogrenciyi yanlis yere yolluyordu. */
    public function test_the_outdated_shelf_qr_tip_is_gone(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertDontSee('Nasıl tüketim eklerim');
    }

    public function test_a_student_with_a_table_is_offered_the_scanner(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertSee(route('table.scanner'), false);
    }

    /** Masasiz paket (yalnizca deneme) okuyucuya giremez; panel onu oraya cagirmamali. */
    public function test_a_student_without_a_table_is_not_offered_the_scanner(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->examOnly()->create());

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertDontSee(route('table.scanner'), false);
    }
}
