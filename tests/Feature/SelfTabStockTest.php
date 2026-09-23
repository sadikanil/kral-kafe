<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 8 - QR tuketim akisi kalkiyor, stok dusumu self adisyona geciyor.
 * Dalga 29 - stok urunun uzerinde (product_locations kalkti).
 *
 * NEDEN BIRLIKTE: stok mutabakati expected_quantity'nin sayimlar ARASINDA
 * tuketimle dusmesine dayaniyor (StockController kapanis sayiminda
 * beklenen ile sayilani karsilastirip farki DiscrepancyLog'a yaziyor).
 * Stogu dusen tek yer QR akisiydi; self adisyon stoga hic dokunmuyordu.
 *
 * QR'i oylece kaldirmak, yoneticinin stok takibini her gun mesru satisi
 * KAYIP olarak isaretler hale getirirdi - kullanicinin "stok takip sistemi
 * admin icin hala aktif olacaktir" dedigi seyi sessizce bozardi.
 */
class SelfTabStockTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->student()->create();
    }

    private function urun(string $ad = 'Canga', float $fiyat = 27, ?int $stok = 10, ?Location $konum = null, ?int $kritik = null): Product
    {
        return Product::create([
            'name' => $ad,
            'unit_price' => $fiyat,
            'unit_type' => 'Paket',
            'is_active' => true,
            'stock_quantity' => $stok,
            'critical_quantity' => $kritik,
            'location_id' => $konum?->id,
        ]);
    }

    private function raf(string $ad = 'Aburcubur Rafı'): Location
    {
        return Location::create(['name' => $ad]);
    }

    // --- Stok dusumu (Dalga 29: stok urunun uzerinde) --------------------------

    public function test_adding_to_the_tab_decrements_the_stock(): void
    {
        $urun = $this->urun(stok: 10);

        $this->actingAs($this->ogrenci())
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 3])
            ->assertRedirect(route('user.tab'));

        $this->assertSame(7, $urun->fresh()->stock_quantity);
    }

    /**
     * Kayit urunun konumuna yazilir: bir fark arastirilirken hangi
     * tuketimin hangi konumu dusurdugu gorunur olmali.
     */
    public function test_the_consumption_records_the_products_location(): void
    {
        $raf = $this->raf();
        $urun = $this->urun(konum: $raf);

        $this->actingAs($this->ogrenci())->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1]);

        $this->assertSame($raf->id, Consumption::sole()->location_id);
    }

    /** Formdan konum gelse bile ogrenci secemez. */
    public function test_a_sent_location_is_ignored(): void
    {
        $raf = $this->raf();
        $urun = $this->urun(konum: $raf);

        $this->actingAs($this->ogrenci())->post('/kullanici/adisyon', [
            'product_id' => $urun->id, 'quantity' => 1, 'location_id' => $this->raf('Depo')->id,
        ]);

        $this->assertSame($raf->id, Consumption::sole()->location_id);
    }

    public function test_undoing_puts_the_stock_back(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun(stok: 10);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 4]);
        $this->assertSame(6, $urun->fresh()->stock_quantity);

        $this->actingAs($ogrenci)->post(route('user.tab.undo', Consumption::sole()));

        $this->assertSame(10, $urun->fresh()->stock_quantity);
    }

    /** Geri alma suresi dolmussa stok da geri gelmez - kayit gecerli kaliyor. */
    public function test_an_expired_undo_leaves_the_stock_alone(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun(stok: 10);

        $kayit = Consumption::create([
            'user_id' => $ogrenci->id,
            'location_id' => Location::selfService()->id,
            'product_id' => $urun->id,
            'quantity' => 2,
            'unit_price' => 25,
            'consumed_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($ogrenci)->post(route('user.tab.undo', $kayit))->assertSessionHas('error');

        $this->assertSame(10, $urun->fresh()->stock_quantity);
    }

    /**
     * Konumu olmayan urun yine de adisyona yazilir: consumptions.location_id
     * NOT NULL, sanal lokasyon (Dalga 6c) yedek kaliyor. Stok yine duser.
     */
    public function test_a_product_without_a_location_still_reaches_the_tab(): void
    {
        $urun = $this->urun(stok: 5);

        $this->actingAs($this->ogrenci())
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1])
            ->assertRedirect(route('user.tab'));

        $this->assertSame(Location::selfService()->id, Consumption::sole()->location_id);
        $this->assertSame(4, $urun->fresh()->stock_quantity);
    }

    /** Takip kapali (sicak icecek): satilir, stok bos kalir. */
    public function test_an_untracked_product_is_sold_without_stock(): void
    {
        $urun = $this->urun('Türk Kahvesi', 60, stok: null);

        $this->actingAs($this->ogrenci())->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 2]);

        $this->assertSame(1, Consumption::count());
        $this->assertNull($urun->fresh()->stock_quantity);
    }

    /** Satis kritik sayiya indirirse yonetici bildirim alir. */
    public function test_a_sale_to_the_critical_level_notifies_the_admin(): void
    {
        $yonetici = User::factory()->admin()->create();
        $urun = $this->urun(stok: 6, kritik: 5);

        $this->actingAs($this->ogrenci())->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1]);

        $this->assertSame(1, \App\Models\Notification::where('user_id', $yonetici->id)->where('type', 'low_stock')->count());
    }

    public function test_the_tab_asks_no_shelf(): void
    {
        $this->urun(konum: $this->raf());

        $this->actingAs($this->ogrenci())->get('/kullanici/adisyon')
            ->assertOk()
            ->assertDontSee('Nereden aldın');
    }

    // --- Kapsam self adisyonda -----------------------------------------------

    /**
     * Paket kapsami self adisyonda UYGULANIR.
     *
     * Kapsam mantigi ConsumptionCoverageTest'te; burada test edilen, adisyon
     * ucunun onu gercekten cagirdigi. Cagirmazsa kapsam kodu dogru calisip
     * hicbir ise yaramaz.
     */
    public function test_a_covered_product_is_added_free(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $paket = Package::create(['name' => 'Standart', 'monthly_price' => 1000]);
        PackageItem::create([
            'package_id' => $paket->id,
            'product_id' => $urun->id,
            'included_quantity' => 2,
            'period' => 'daily',
        ]);
        Subscription::create([
            'student_id' => $ogrenci->id,
            'package_id' => $paket->id,
            'price' => 1000,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 2]);

        $kayit = Consumption::sole();

        $this->assertSame(2, $kayit->covered_quantity);
        $this->assertSame('0.00', (string) $kayit->total_price);
        $this->assertTrue($kayit->isCoveredByPackage());
    }

    /** Limit asiminda engelleme yok: kalan kapsanir, gerisi ucretlenir (SS7). */
    public function test_exceeding_the_limit_is_charged_not_blocked(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Filtre Kahve', 25);

        $paket = Package::create(['name' => 'Standart', 'monthly_price' => 1000]);
        PackageItem::create([
            'package_id' => $paket->id,
            'product_id' => $urun->id,
            'included_quantity' => 1,
            'period' => 'daily',
        ]);
        Subscription::create([
            'student_id' => $ogrenci->id,
            'package_id' => $paket->id,
            'price' => 1000,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($ogrenci)
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 3])
            ->assertRedirect(route('user.tab'));

        $kayit = Consumption::sole();

        $this->assertSame(1, $kayit->covered_quantity);
        $this->assertSame('50.00', (string) $kayit->total_price);
    }

    // --- QR akisi kalkti -----------------------------------------------------

    /**
     * Kullanici acikca istedi: "bunun icin ayrica bir qr okutma yapmani
     * istemiyorum". Lokasyon QR'lari STOK SAYIMI icin duruyor; kaldirilan
     * yalnizca ogrencinin urun tuketmek icin QR okutmasi.
     */
    public function test_the_qr_consumption_page_is_gone(): void
    {
        $raf = $this->raf();

        $this->actingAs($this->ogrenci())
            ->get('/tuketim/' . $raf->qr_code)
            ->assertNotFound();
    }

    public function test_the_qr_consumption_endpoints_are_gone(): void
    {
        $this->assertFalse(app('router')->has('user.consume.store'), 'consume.store hala tanimli');
        $this->assertFalse(app('router')->has('user.consume.undo'), 'consume.undo hala tanimli');
        $this->assertFalse(app('router')->has('user.consume.summary'), 'consume.summary hala tanimli');
    }
}
