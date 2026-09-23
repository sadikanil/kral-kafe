<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Product;
use App\Models\StockRecord;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PaymentStatement;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ay ve gun sinirlari KAFE saatine gore (README SS9.1.3, SS10.1).
 *
 * Saat damgalari UTC; whereMonth/whereDate UTC gunune baktigi icin yerel
 * 00:00-03:00 arasindaki adisyon bir onceki gune, ay basindaysa bir onceki
 * AYA yaziliyordu. Fatura, Odemeler, raporlar ve panel ayni kurali kullanir.
 *
 * Kurgu: simdi yerel 1 Ekim 00:30 (UTC 30 Eylul 21:30).
 *   EKIM   -> UTC 30 Eyl 21:10 = yerel 1 Eki 00:10  (45 TL)
 *   EYLUL  -> UTC 30 Eyl 20:50 = yerel 30 Eyl 23:50 (30 TL)
 */
class LocalTimeBucketsTest extends TestCase
{
    use RefreshDatabase;

    private User $ogrenci;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 21:30:00', 'UTC'));
        $this->ogrenci = User::factory()->student()->create();

        $this->tuket('2026-09-30 21:10:00', 45);
        $this->tuket('2026-09-30 20:50:00', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tuket(string $utc, int $fiyat): void
    {
        $urun = Product::create(['name' => "Ürün {$fiyat}", 'unit_price' => $fiyat, 'unit_type' => 'adet']);
        $raf = Location::firstOrCreate(['qr_code' => 'LOC-T'], ['name' => 'Raf', 'type' => 'shelf']);

        Consumption::create([
            'user_id' => $this->ogrenci->id, 'product_id' => $urun->id, 'location_id' => $raf->id,
            'quantity' => 1, 'unit_price' => $fiyat, 'consumed_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }

    public function test_the_local_day_is_already_the_first_of_october(): void
    {
        $this->assertSame('2026-10-01', LocalDay::today());
    }

    public function test_the_month_scope_uses_the_local_month(): void
    {
        $this->assertEquals(45, Consumption::inLocalMonth(2026, 10)->sum('total_price'));
        $this->assertEquals(30, Consumption::inLocalMonth(2026, 9)->sum('total_price'));
    }

    public function test_the_day_scope_uses_the_local_day(): void
    {
        $this->assertEquals(45, Consumption::onLocalDay(LocalDay::today())->sum('total_price'));
    }

    public function test_the_current_month_scope_is_the_local_one(): void
    {
        $this->assertEquals(45, Consumption::currentMonth()->sum('total_price'));
    }

    public function test_the_statement_follows_the_local_month(): void
    {
        $this->assertEquals(45, PaymentStatement::for($this->ogrenci, 2026, 10)->spendingTotal());
        $this->assertEquals(30, PaymentStatement::for($this->ogrenci, 2026, 9)->spendingTotal());
    }

    public function test_the_bill_follows_the_local_month(): void
    {
        $this->assertEquals(45, MonthlyBill::generateForUserMonth($this->ogrenci, 2026, 10)->total_amount);
        $this->assertEquals(30, MonthlyBill::generateForUserMonth($this->ogrenci, 2026, 9)->total_amount);
    }

    /** now()->setMonth() ayin 31'inde tasiyordu: 31 Eki'de "31 Eylul" = 1 Ekim. */
    public function test_a_bill_made_on_the_31st_keeps_its_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));

        $fatura = MonthlyBill::generateForUserMonth($this->ogrenci, 2026, 9);

        $this->assertSame('2026-09-01', $fatura->bill_month->toDateString());
    }

    public function test_the_monthly_summary_follows_the_local_month(): void
    {
        $ozet = app(BillingService::class)->getUserMonthlySummary($this->ogrenci, 2026, 10);

        $this->assertEquals(45, $ozet['total_amount']);
    }

    public function test_the_dashboard_stats_use_the_local_month_and_day(): void
    {
        $istatistik = app(BillingService::class)->getDashboardStats();

        $this->assertEquals(45, $istatistik['this_month_total']);
        $this->assertEquals(45, $istatistik['today_total']);
    }

    public function test_the_students_month_total_is_the_local_one(): void
    {
        $this->assertEquals(45, $this->ogrenci->getCurrentMonthTotal());
        $this->assertSame(1, $this->ogrenci->getCurrentMonthItemCount());
    }

    public function test_todays_stock_records_use_the_local_day(): void
    {
        $kayit = fn (string $utc) => StockRecord::create([
            'location_id' => Location::first()->id, 'product_id' => Product::first()->id,
            'record_type' => 'closing', 'verified_quantity' => 1, 'admin_id' => $this->ogrenci->id,
            'recorded_at' => Carbon::parse($utc, 'UTC'),
        ]);
        $kayit('2026-09-30 21:10:00'); // yerel bugun
        $kayit('2026-09-30 20:50:00'); // yerel dun

        $this->assertSame(1, StockRecord::today()->count());
    }

    public function test_the_admin_dashboard_lists_only_local_today(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('todayConsumptions', fn ($liste) => $liste->count() === 1);
    }

    public function test_the_local_year_and_month(): void
    {
        $this->assertSame([2026, 10], LocalDay::yearMonth());
    }

    /** UX turu (23 Eyl): ay toplami panelden Adisyon'a tasindi. */
    public function test_the_student_tab_summarises_the_local_month(): void
    {
        $this->actingAs($this->ogrenci)->get(route('user.tab'))
            ->assertViewHas('monthTotal', fn ($toplam) => (float) $toplam === 45.0);
    }

    public function test_the_report_page_opens_on_the_local_month(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.reports.index'))
            ->assertViewHas('stats', fn ($s) => (float) $s['monthly_revenue'] === 45.0);
    }

    // --- Gosterim: saatler kafe saatiyle -------------------------------------
    // Ekran goruntusu (23 Eyl): 18:33'te eklenen adisyon "15:23" gorunuyordu.

    public function test_the_student_tab_shows_local_times(): void
    {
        $this->actingAs($this->ogrenci)->get(route('user.tab'))
            ->assertSee('00:10')
            ->assertDontSee('21:10');
    }

    public function test_the_admin_panel_shows_local_times(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertSee('00:10')
            ->assertDontSee('21:10');
    }

    public function test_the_formatted_date_is_local(): void
    {
        $this->assertSame('01.10.2026 00:10', Consumption::inLocalMonth(2026, 10)->sole()->formatted_date);
    }

    public function test_the_daily_breakdown_groups_by_local_day(): void
    {
        $ozet = app(BillingService::class)->getUserMonthlySummary($this->ogrenci, 2026, 10);

        $this->assertSame(['2026-10-01'], $ozet['by_day']->pluck('date')->all());
    }
}
