<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 8 - QR tuketim akisi kalkiyor, stok dusumu self adisyona geciyor.
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

    private function urun(string $ad = 'Filtre Kahve', float $fiyat = 25): Product
    {
        return Product::create([
            'name' => $ad,
            'unit_price' => $fiyat,
            'unit_type' => 'adet',
            'is_active' => true,
        ]);
    }

    private function raf(string $ad = 'Buzdolabı'): Location
    {
        return Location::create(['name' => $ad, 'is_active' => true]);
    }

    private function yerlestir(Product $urun, Location $raf, int $adet): ProductLocation
    {
        return ProductLocation::create([
            'product_id' => $urun->id,
            'location_id' => $raf->id,
            'expected_quantity' => $adet,
            'min_quantity' => 0,
        ]);
    }

    // --- Stok dusumu ---------------------------------------------------------

    public function test_adding_to_the_tab_decrements_the_shelf(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $raf = $this->raf();
        $stok = $this->yerlestir($urun, $raf, 10);

        $this->actingAs($ogrenci)
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 3])
            ->assertRedirect(route('user.tab'));

        $this->assertSame(7, $stok->fresh()->expected_quantity);
    }

    /**
     * Kayit urunun GERCEK lokasyonuna yazilir.
     *
     * Dalga 6c'de self adisyon her zaman sanal "Self Adisyon" lokasyonuna
     * yaziyordu cunku stokla ilgisi yoktu. Artik var: stok dusumunun hangi
     * rafa yazildigi kaydin kendisinde gorunmeli, yoksa bir fark
     * arastirilirken hangi tuketimin hangi rafi dusurdugu bilinemezdi.
     */
    public function test_the_consumption_records_the_real_shelf(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $raf = $this->raf();
        $this->yerlestir($urun, $raf, 10);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1]);

        $this->assertSame($raf->id, Consumption::sole()->location_id);
    }

    public function test_undoing_puts_the_stock_back(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $raf = $this->raf();
        $stok = $this->yerlestir($urun, $raf, 10);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 4]);
        $this->assertSame(6, $stok->fresh()->expected_quantity);

        $this->actingAs($ogrenci)->post(route('user.tab.undo', Consumption::sole()));

        $this->assertSame(10, $stok->fresh()->expected_quantity);
    }

    /** Geri alma suresi dolmussa stok da geri gelmez - kayit gecerli kaliyor. */
    public function test_an_expired_undo_leaves_the_stock_alone(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $raf = $this->raf();
        $stok = $this->yerlestir($urun, $raf, 10);

        $kayit = Consumption::create([
            'user_id' => $ogrenci->id,
            'location_id' => $raf->id,
            'product_id' => $urun->id,
            'quantity' => 2,
            'unit_price' => 25,
            'consumed_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($ogrenci)->post(route('user.tab.undo', $kayit))->assertSessionHas('error');

        $this->assertSame(10, $stok->fresh()->expected_quantity);
    }

    // --- Rafi olmayan urun ---------------------------------------------------

    /**
     * Hicbir rafa bagli olmayan urun yine de adisyona yazilir.
     *
     * Sanal lokasyon (Dalga 6c) bu durum icin KALIYOR: consumptions.location_id
     * NOT NULL ve her ekran location->name okuyor. Stok dusumu yok - dusurulecek
     * bir kayit da yok.
     */
    public function test_a_product_on_no_shelf_still_reaches_the_tab(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();

        $this->actingAs($ogrenci)
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1])
            ->assertRedirect(route('user.tab'));

        $kayit = Consumption::sole();

        $this->assertSame(Location::selfService()->id, $kayit->location_id);
        $this->assertSame(0, ProductLocation::count());
    }

    /** Kapali raf sayilmaz: stok ekranlarindan cikmis bir raf dusurulmemeli. */
    public function test_an_inactive_shelf_is_ignored(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $kapali = Location::create(['name' => 'Eski Raf', 'is_active' => false]);
        $stok = $this->yerlestir($urun, $kapali, 10);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1]);

        $this->assertSame(Location::selfService()->id, Consumption::sole()->location_id);
        $this->assertSame(10, $stok->fresh()->expected_quantity);
    }

    // --- Birden fazla raf ----------------------------------------------------

    /**
     * Dalga 26 (karar, 23 Eyl): ogrenciye raf SORULMAZ. Urun birden fazla
     * raftaysa stok EN COK stoku olan raftan duser - deterministik, ve
     * tahminin yanildigi yerde yonetici kapanis sayiminda duzeltir.
     * (Onceki kural "ogrenci secsin"di; kullanici secim istemedi.)
     */
    public function test_a_product_on_two_shelves_needs_no_choice(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $buzdolabi = $this->yerlestir($urun, $this->raf('Buzdolabı'), 10);
        $raf = $this->yerlestir($urun, $this->raf('Aburcubur Rafı'), 5);

        $this->actingAs($ogrenci)
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(8, $buzdolabi->fresh()->expected_quantity);
        $this->assertSame(5, $raf->fresh()->expected_quantity);
    }

    /** Formdan raf gelse bile ogrenci secemez; kural ayni. */
    public function test_a_sent_shelf_is_ignored(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun();
        $buzdolabi = $this->yerlestir($urun, $this->raf('Buzdolabı'), 10);
        $raf = $this->yerlestir($urun, $this->raf('Aburcubur Rafı'), 5);

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', [
            'product_id' => $urun->id, 'quantity' => 1, 'location_id' => $raf->location_id,
        ]);

        $this->assertSame(9, $buzdolabi->fresh()->expected_quantity);
    }

    public function test_the_tab_asks_no_shelf(): void
    {
        $urun = $this->urun();
        $this->yerlestir($urun, $this->raf('Buzdolabı'), 10);
        $this->yerlestir($urun, $this->raf('Aburcubur Rafı'), 5);

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
