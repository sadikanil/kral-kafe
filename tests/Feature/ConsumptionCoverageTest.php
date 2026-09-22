<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PackageCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 8 - Tuketimin paket kapsamina baglanmasi.
 *
 * Dalga 7'de package_items YALNIZCA TANIMDI: paketin neyi kapsadigi
 * yaziliydi ama tuketim onu hic okumuyordu, her sey tam fiyattan
 * faturalaniyordu. Bu dalga tanimi uyguluyor.
 *
 * Karar (SS7): limit asiminda ENGELLEME YOK, ucretlendir. Engellemek
 * ogrenciyi kasaya yonlendirir - self adisyonun butun amaci o degil.
 */
class ConsumptionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->student()->create();
    }

    private function urun(string $ad = 'Filtre Kahve', float $fiyat = 25): Product
    {
        return Product::create([
            'name' => $ad,
            'unit_price' => $fiyat,
            'unit_type' => 'adet',
            'is_active' => true,
        ]);
    }

    /**
     * Ogrenciye paket atar ve kapsam kalemini kurar.
     *
     * $adet null = sinirsiz.
     */
    private function paketle(User $ogrenci, Product $urun, ?int $adet, string $donem = 'daily'): Package
    {
        $paket = Package::create(['name' => 'Standart', 'monthly_price' => 1000]);

        PackageItem::create([
            'package_id' => $paket->id,
            'product_id' => $urun->id,
            'included_quantity' => $adet,
            'period' => $donem,
        ]);

        Subscription::create([
            'student_id' => $ogrenci->id,
            'package_id' => $paket->id,
            'price' => 1000,
            // Genis aralik: donem testleri travelTo ile yil icinde geziniyor,
            // dar bir abonelik onlari kapsam disinda birakirdi.
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);

        return $paket;
    }

    private function kapsam(): PackageCoverage
    {
        return app(PackageCoverage::class);
    }

    private function tuket(User $ogrenci, Product $urun, int $adet, int $kapsanan): Consumption
    {
        return Consumption::create([
            'user_id' => $ogrenci->id,
            'location_id' => Location::selfService()->id,
            'product_id' => $urun->id,
            'quantity' => $adet,
            'covered_quantity' => $kapsanan,
            'unit_price' => $urun->unit_price,
        ]);
    }

    // --- Kapsam disi ---------------------------------------------------------

    public function test_a_student_without_a_package_is_covered_for_nothing(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();

        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 2));
    }

    /** Pakette olmayan urun kapsam disi - paket varken bile. */
    public function test_a_product_outside_the_package_is_not_covered(): void
    {
        $ogrenci = $this->ogrenci();
        $kapsanan = $this->urun('Filtre Kahve');
        $baskasi = $this->urun('Tost', 60);
        $this->paketle($ogrenci, $kapsanan, 2);

        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $baskasi, 1));
    }

    // --- Sinirsiz ------------------------------------------------------------

    public function test_an_unlimited_item_covers_the_whole_request(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, null);

        $this->assertSame(5, $this->kapsam()->coveredQuantity($ogrenci, $urun, 5));
    }

    // --- Limit ---------------------------------------------------------------

    public function test_a_request_inside_the_limit_is_fully_covered(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 3);

        $this->assertSame(2, $this->kapsam()->coveredQuantity($ogrenci, $urun, 2));
    }

    public function test_earlier_consumption_eats_into_the_limit(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 3);

        $this->tuket($ogrenci, $urun, 2, 2);

        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 2));
    }

    /**
     * Limit asimi KISMI kapsanir: kalan kadari bedava, gerisi ucretli.
     *
     * Alternatifi "tamami sigmiyorsa hicbiri kapsanmaz" olurdu - ogrenci
     * bir hakki kalmisken iki kahve alinca IKISINE de para oderdi. Onu
     * savunmak zor.
     */
    public function test_exceeding_the_limit_is_covered_partially(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 3);

        $this->tuket($ogrenci, $urun, 2, 2);

        // Kalan 1; 3 istendi -> 1 kapsanir, 2 ucretlenir.
        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 3));
    }

    public function test_an_exhausted_limit_covers_nothing(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 2);

        $this->tuket($ogrenci, $urun, 2, 2);

        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    /**
     * UCRETLENDIRILMIS adet limiti yemez.
     *
     * Sayim covered_quantity uzerinden; quantity uzerinden sayilsaydi limit
     * asiminda odenen adet de hakki tuketir ve ogrenci iki kez cezalandirilmis
     * olurdu.
     */
    public function test_a_charged_quantity_does_not_eat_the_limit(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 3);

        // 1 kapsandi, 4 ucretlendi.
        $this->tuket($ogrenci, $urun, 5, 1);

        $this->assertSame(2, $this->kapsam()->coveredQuantity($ogrenci, $urun, 2));
    }

    /** Geri alinan kayit hakki geri verir. */
    public function test_an_undone_consumption_gives_the_limit_back(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 2);

        $kayit = $this->tuket($ogrenci, $urun, 2, 2);
        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));

        $kayit->undo();

        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    // --- Donem penceresi -----------------------------------------------------

    /**
     * Gunluk hak ertesi gun yenilenir - ve sinir KAFE saatine gore.
     *
     * whereDate ile UTC gunune bakilsaydi yerel 00:00-03:00 arasindaki
     * tuketim bir onceki gune duser ve ogrenci gece yarisindan sonra iki
     * kat hak kullanirdi (SS10.1'deki tuzagin tuketim bicimi).
     */
    public function test_a_daily_limit_resets_the_next_local_day(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 1, 'daily');

        $this->travelTo(Carbon::parse('2026-09-16 14:00', config('kafe.timezone')));
        $this->tuket($ogrenci, $urun, 1, 1);
        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));

        // Yerel ertesi gun 01:00 - UTC'de hala 16 Eylul.
        $this->travelTo(Carbon::parse('2026-09-17 01:00', config('kafe.timezone')));
        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    /** Gece yarisindan SONRA ama ayni yerel gun icinde hak yenilenmez. */
    public function test_after_midnight_still_belongs_to_the_same_local_day(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 1, 'daily');

        // Yerel 17 Eylul 01:00'de tuketti (UTC'de 16 Eylul 22:00).
        $this->travelTo(Carbon::parse('2026-09-17 01:00', config('kafe.timezone')));
        $this->tuket($ogrenci, $urun, 1, 1);

        // Ayni yerel gunun ogleni - hak hala dolu olmali.
        $this->travelTo(Carbon::parse('2026-09-17 12:00', config('kafe.timezone')));
        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    public function test_a_weekly_limit_spans_the_local_week(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 2, 'weekly');

        // Pazartesi 14 Eylul
        $this->travelTo(Carbon::parse('2026-09-14 10:00', config('kafe.timezone')));
        $this->tuket($ogrenci, $urun, 2, 2);

        // Ayni haftanin persembesi - hak bitmis
        $this->travelTo(Carbon::parse('2026-09-17 10:00', config('kafe.timezone')));
        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));

        // Sonraki pazartesi - yenilendi
        $this->travelTo(Carbon::parse('2026-09-21 10:00', config('kafe.timezone')));
        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    public function test_a_monthly_limit_spans_the_local_month(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $this->paketle($ogrenci, $urun, 4, 'monthly');

        $this->travelTo(Carbon::parse('2026-09-30 23:00', config('kafe.timezone')));
        $this->tuket($ogrenci, $urun, 4, 4);
        $this->assertSame(0, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));

        $this->travelTo(Carbon::parse('2026-10-01 09:00', config('kafe.timezone')));
        $this->assertSame(1, $this->kapsam()->coveredQuantity($ogrenci, $urun, 1));
    }

    // --- Fiyat ---------------------------------------------------------------

    /**
     * Fiyat kapsamdan SONRA hesaplanir: yalnizca ucretlenen adet odenir.
     *
     * Consumption::boot bu ana kadar total_price'i KOSULSUZ
     * unit_price * quantity yaziyordu; kapsam girince bu dogrudan yanlis
     * fatura uretirdi (SS4.2'de Dalga 8'in onkosulu olarak isaretliydi).
     */
    public function test_a_fully_covered_consumption_costs_nothing(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $kayit = $this->tuket($ogrenci, $urun, 2, 2);

        $this->assertSame('0.00', (string) $kayit->total_price);
        $this->assertTrue($kayit->isCoveredByPackage());
    }

    public function test_a_partially_covered_consumption_charges_the_rest(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $kayit = $this->tuket($ogrenci, $urun, 3, 1);

        $this->assertSame('50.00', (string) $kayit->total_price);
        $this->assertFalse($kayit->isCoveredByPackage());
    }

    public function test_an_uncovered_consumption_costs_full_price(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $kayit = $this->tuket($ogrenci, $urun, 2, 0);

        $this->assertSame('50.00', (string) $kayit->total_price);
    }

    /**
     * Kapsanan tuketim aylik faturaya 0 olarak girer ama KAYIT OLARAK DURUR.
     *
     * Karar (SS7): Consumption her zaman olusur. Kayit stok dusumunu ve
     * 60 saniyelik geri almayi suruyor; kapsanani hic yazmamak ikisini de
     * kaybettirirdi.
     */
    public function test_a_covered_consumption_still_exists_and_bills_zero(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $this->tuket($ogrenci, $urun, 2, 2);

        $this->assertSame(1, Consumption::count());
        $this->assertSame(0.0, (float) $ogrenci->getCurrentMonthTotal());
        $this->assertSame(2, $ogrenci->getCurrentMonthItemCount());
    }
}
