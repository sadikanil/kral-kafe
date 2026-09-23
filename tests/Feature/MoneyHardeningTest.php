<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockRecord;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Para alani (urun, adisyon, odemeler, paketler, raporlar) icin QA turunun
 * ardindan eklenen korumalar. Duman testlerinin kapsamadigi yan durumlar.
 *
 * Saat: 29 Eylul 2026 sali 14:00 (kafe saati).
 */
class MoneyHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function urun(string $ad = 'Canga', ?Location $konum = null, ?int $stok = 10): Product
    {
        return Product::create([
            'name' => $ad, 'unit_price' => 25, 'unit_type' => 'Paket',
            'location_id' => $konum?->id, 'stock_quantity' => $stok, 'is_active' => true,
        ]);
    }

    // --- Paket atama ---------------------------------------------------------

    /** Cift atama korumasi iptal edilmis donemi saymaz: iptal et, yeniden ata. */
    public function test_a_cancelled_package_can_be_assigned_again_for_the_same_start(): void
    {
        $yonetici = $this->yonetici();
        $paket = Package::factory()->tier1()->create(['monthly_price' => 7500]);
        $ogrenci = User::factory()->student()->create();
        $veri = ['package_id' => $paket->id, 'starts_on' => '2026-10-01'];

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $ogrenci), $veri)
            ->assertSessionHas('success', 'Paket atandı.');
        $this->actingAs($yonetici)->post(route('admin.subscriptions.cancel', $ogrenci->subscriptions()->sole()));

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $ogrenci), $veri)
            ->assertSessionHas('success', 'Paket atandı.');

        $this->assertSame(1, $ogrenci->subscriptions()->where('payment_status', '!=', PaymentStatus::Cancelled->value)->count());
        $this->assertSame(2, $ogrenci->subscriptions()->count());
    }

    /** Ayni paket, ayni baslangic ikinci kez gonderilince yonetici neden acilmadigini gorur. */
    public function test_a_repeated_assignment_says_it_is_already_assigned(): void
    {
        $yonetici = $this->yonetici();
        $paket = Package::factory()->tier1()->create();
        $ogrenci = User::factory()->student()->create();
        $veri = ['package_id' => $paket->id, 'starts_on' => '2026-10-01'];

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $ogrenci), $veri);
        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $ogrenci), $veri)
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci))
            ->assertSessionHas('success', 'Bu paket bu tarihten itibaren zaten atanmış; ikinci kez açılmadı.');

        $this->assertSame(1, $ogrenci->subscriptions()->count());
    }

    // --- Odeme kaydi ---------------------------------------------------------

    /**
     * Cift dokunma korumasi esit taksitleri engellememeli: ayni gun ayni
     * tutar sabah ve aksam alinabilir. Yalnizca bir dakika icindeki tekrar elenir.
     */
    public function test_an_equal_payment_recorded_minutes_later_is_a_second_instalment(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1()->create(['monthly_price' => 7500]))->create();
        $abonelik = $ogrenci->subscriptions()->sole();
        $veri = ['amount' => '3000', 'paid_at' => '2026-09-29', 'method' => 'cash', 'note' => ''];

        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), $veri);
        $this->travel(5)->minutes();
        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), $veri)
            ->assertSessionHas('success', 'Ödeme kaydedildi. Kalan: 1.500,00 ₺');

        $this->assertSame(2, $abonelik->payments()->count());
    }

    /** Tekrar elenince de yonetici guncel kalani gorur (ikinci yanit ekranda kalan yanittir). */
    public function test_a_double_tapped_payment_still_answers_with_the_balance(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1()->create(['monthly_price' => 7500]))->create();
        $abonelik = $ogrenci->subscriptions()->sole();
        $veri = ['amount' => '3000', 'paid_at' => '2026-09-29', 'method' => 'cash', 'note' => 'Nakit - anne'];

        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), $veri);
        $this->travel(3)->seconds();
        $this->actingAs($yonetici)->post(route('admin.subscriptions.payments.store', $abonelik), $veri)
            ->assertSessionHas('success', 'Ödeme kaydedildi. Kalan: 4.500,00 ₺');

        $this->assertSame(1, $abonelik->payments()->count());
    }

    // --- Sorgu sayisi --------------------------------------------------------

    /**
     * Istek sirasinda calisan SQL metinleri.
     *
     * @return list<string>
     */
    private function sorgular(callable $istek): array
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $istek();
        $sorgular = array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query');
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $sorgular;
    }

    /**
     * Odeme takibi odemeleri zaten on yukluyor; senkron, "Odenen" ve "Kalan"
     * satir basina ayrica sum() sorgusu atmamali (40 abonelikte 120 fazla
     * sorgu, Supabase'e her biri bir gidis-donus). Sorgu sayisi abonelik
     * sayisiyla buyumemeli.
     */
    public function test_the_payments_overview_does_not_query_each_subscriptions_payments(): void
    {
        $yonetici = $this->yonetici();
        $paket = Package::factory()->tier1()->create(['monthly_price' => 7500]);
        $abonelikler = Subscription::factory()->count(12)->create(['package_id' => $paket->id]);
        foreach ($abonelikler->take(6) as $abonelik) {
            Payment::factory()->create(['subscription_id' => $abonelik->id, 'amount' => 3000]);
        }
        // Ilk acilis vadesi gecenleri "Gecikmis"e yazar (durum degisince bir
        // kez); olculen, durumlar oturmus gunluk acilis.
        $this->actingAs($yonetici)->get(route('admin.subscriptions.overview'))->assertOk();

        $sorgular = $this->sorgular(fn () => $this->actingAs($yonetici)
            ->get(route('admin.subscriptions.overview'))
            ->assertOk()
            ->assertSee('4.500,00 ₺')      // kismi odenen: kalan
            ->assertSee('7.500,00 ₺'));

        $toplamlar = array_filter($sorgular, fn ($s) => str_contains($s, 'sum(') && str_contains($s, 'payments'));
        $this->assertSame([], array_values($toplamlar));
        $this->assertLessThan(12, count($sorgular), implode("\n", $sorgular));
    }

    public function test_a_students_payment_page_does_not_query_each_subscriptions_payments(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = User::factory()->student()->create();
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $bas) {
            $abonelik = Subscription::factory()->create([
                'student_id' => $ogrenci->id, 'starts_on' => $bas,
                'ends_on' => Carbon::parse($bas)->endOfMonth()->toDateString(),
            ]);
            Payment::factory()->create(['subscription_id' => $abonelik->id, 'amount' => 3000]);
        }

        $sorgular = $this->sorgular(fn () => $this->actingAs($yonetici)
            ->get(route('admin.subscriptions.index', $ogrenci))
            ->assertOk());

        $toplamlar = array_filter($sorgular, fn ($s) => str_contains($s, 'sum(') && str_contains($s, 'payments'));
        $this->assertSame([], array_values($toplamlar));
    }

    /** On yuklenmis iliski yoksa taze sorgu: yeni yazilan odeme kalani hemen dusurur. */
    public function test_the_balance_without_loaded_payments_reads_fresh(): void
    {
        $abonelik = Subscription::factory()->create(['price' => 7500]);
        Payment::factory()->create(['subscription_id' => $abonelik->id, 'amount' => 3000]);

        $this->assertSame(4500.0, $abonelik->balance());
    }

    // --- Aylik fatura --------------------------------------------------------

    /**
     * Faturayi o AYDA olan belirler, bugunku erisim durumu degil: Agustos
     * paketi acilip sonra askiya alinan ogrencinin Agustos paket bedeli
     * faturaya girmeli; o ay hicbir seyi olmayan pasif ogrenciye fatura cikmaz.
     */
    public function test_bills_follow_the_billed_months_activity_not_todays_status(): void
    {
        $askida = User::factory()->student()->create(['name' => 'Askıda Öğrenci']);
        Subscription::factory()->create([
            'student_id' => $askida->id, 'package_id' => Package::factory()->tier1()->create()->id,
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'price' => 7500,
        ]);
        $askida->update(['subscription_status' => 'suspended']);
        $eski = User::factory()->student()->create(['name' => 'Eski Öğrenci', 'subscription_status' => 'inactive']);

        $this->actingAs($this->yonetici())
            ->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 8])
            ->assertSessionHas('success', '1 fatura oluşturuldu.');

        $fatura = MonthlyBill::sole();
        $this->assertSame($askida->id, $fatura->user_id);
        $this->assertEquals(7500, $fatura->package_amount);
        $this->assertNull(MonthlyBill::where('user_id', $eski->id)->first());
    }

    /**
     * Yerel 1 Ekim 01:00 = UTC 30 Eylul 22:00. Donem verilmeyen her rapor
     * adresi (CSV'ler, ogrenci raporu) kafe ayini, yani Ekim'i acmali.
     */
    public function test_report_downloads_and_the_student_report_default_to_the_local_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 01:00', config('kafe.timezone')));
        $yonetici = $this->yonetici();
        $ogrenci = User::factory()->student()->create(['name' => 'Ayşe Yılmaz']);

        $this->actingAs($yonetici)->get(route('admin.reports.export-summary'))
            ->assertHeader('Content-Disposition', 'attachment; filename="kral-kafe-ozet-2026-10.csv"');
        $this->actingAs($yonetici)->get(route('admin.reports.export-detailed') . '?year=&month=')
            ->assertHeader('Content-Disposition', 'attachment; filename="kral-kafe-detay-2026-10.csv"');
        $this->actingAs($yonetici)->get(route('admin.reports.user', $ogrenci))
            ->assertOk()->assertSee('Ayşe Yılmaz - Ekim 2026');
    }

    /** Yil secicisi de kafe yilindan baslar: yerel 1 Ocak 01:00'de 2027 secilebilmeli. */
    public function test_report_year_pickers_start_at_the_local_year(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 01:00', config('kafe.timezone')));
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.reports.index'))
            ->assertOk()->assertSee('<option value="2027" selected>', false);
        $this->actingAs($yonetici)->get(route('admin.reports.monthly'))
            ->assertOk()->assertSee('<option value="2027" selected>', false);
    }

    // --- Adisyon ekrani (erisilebilirlik) ------------------------------------

    /**
     * 390px telefonda "Bugun eklediklerim" tablosu kutusundan tasiyor, "Geri al"
     * yatay kaydirmanin arkasinda kaliyordu. Liste satiri: dugme her zaman
     * gorunur ve adiyla okunur; adet secicisi urun adiyla etiketli.
     */
    public function test_the_tab_lists_todays_entries_with_a_visible_named_undo(): void
    {
        $ogrenci = User::factory()->student()->create();
        $urun = $this->urun('Çikolatalı Gofret', null, 10);
        $this->actingAs($ogrenci)->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => 2]);
        $kayit = \App\Models\Consumption::sole();

        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Bugün eklediklerim')
            ->assertDontSee('<table', false)
            ->assertSee('<ul class="log-list">', false)
            ->assertSeeInOrder(['14:00', 'Çikolatalı Gofret ×2', '50,00 ₺', route('user.tab.undo', $kayit)], false)
            ->assertSee('aria-label="Çikolatalı Gofret ×2 kaydını geri al"', false)
            ->assertSee('aria-label="Çikolatalı Gofret adet"', false);
    }

    // --- Urun listesi (erisilebilirlik) --------------------------------------

    /**
     * A5: ⏸️ Duzenle'nin hemen yaninda; yanlis dokunus urunu aninda satistan
     * kaldiriyordu ve dugmelerin adi yalnizca title'daydi (dokunmatikte yok).
     */
    public function test_product_row_actions_are_named_and_the_toggle_asks_first(): void
    {
        $this->urun("Kral'ın Tostu");
        $pasif = $this->urun('Sahlep');
        $pasif->update(['is_active' => false]);

        $this->actingAs($this->yonetici())->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('aria-label="Pasifleştir: Kral&#039;ın Tostu"', false)
            ->assertSee('aria-label="Aktifleştir: Sahlep"', false)
            ->assertSee('aria-label="Sil: Sahlep"', false)
            ->assertSee('onsubmit="return confirm(\'Kral\u0027\u0131n Tostu sat\u0131\u015ftan kald\u0131r\u0131ls\u0131n m\u0131?\')"', false)
            ->assertSee('onsubmit="return confirm(\'Sahlep yeniden sat\u0131\u015fa a\u00e7\u0131ls\u0131n m\u0131?\')"', false);
    }

    // --- Urun silme ----------------------------------------------------------

    /** Stok sayim gecmisi de gecmistir: sayimi yapilmis urun silinmez. */
    public function test_a_counted_product_is_not_deleted_so_its_stock_history_stays(): void
    {
        $yonetici = $this->yonetici();
        $raf = Location::firstOrCreate(['name' => 'Aburcubur Rafı'], ['type' => 'shelf', 'is_active' => true]);
        $urun = $this->urun('Canga', $raf);
        $sayim = StockRecord::create([
            'location_id' => $raf->id, 'product_id' => $urun->id, 'admin_id' => $yonetici->id,
            'verified_quantity' => 10, 'record_type' => 'opening', 'recorded_at' => now(),
        ]);

        $this->actingAs($yonetici)->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $urun))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('error');

        $this->assertModelExists($urun);
        $this->assertModelExists($sayim);
    }

    /** Yer imine alinmis /yonetim/urunler/{id} adresi urunun duzenleme sayfasini acar. */
    public function test_the_product_address_opens_its_edit_page(): void
    {
        $urun = $this->urun('Ayran');

        $this->actingAs($this->yonetici())->get('/yonetim/urunler/' . $urun->id)
            ->assertRedirect(route('admin.products.edit', $urun));
    }
}
