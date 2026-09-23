<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use App\Enums\SessionEndReason;
use App\Models\Consumption;
use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\StockRecord;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yonetim - kafe alani: urunler, stok (tablo, toplu giris, fotografli sayim,
 * tutarsizlik), masalar ve raporlar. Uctan uca duman testi (QA turu).
 *
 * Her rota gercekci veriyle calistirilir; yonetici disindaki roller 403 alir,
 * misafir girise yonlenir. Yapay zeka (OpenAI) cagrilari Http::fake ile,
 * fotograflar Storage::fake ile sahtelenir.
 *
 * Saat: 29 Eylul 2026 sali 14:00 (kafe saati).
 */
class AdminCafeSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    // --- Yardimcilar ---------------------------------------------------------

    private function yonetici(string $ad = 'Yönetici Şahin Öztürk'): User
    {
        return User::factory()->admin()->create(['name' => $ad]);
    }

    private function ogrenci(string $ad = 'Ayşe Yılmaz', array $ek = []): User
    {
        return User::factory()->student()
            ->withPackage(Package::factory()->tier1()->create())
            ->create(array_merge(['name' => $ad], $ek));
    }

    private function konum(string $ad, string $tur = 'shelf'): Location
    {
        // Goc 4 konum etiketi tohumluyor (Buzdolabi, Aburcubur Rafi...); ayni ad yeniden kullanilir.
        return Location::firstOrCreate(['name' => $ad], ['type' => $tur, 'is_active' => true]);
    }

    private function urun(string $ad, ?Location $konum = null, ?int $stok = null, ?int $kritik = null, array $ek = []): Product
    {
        return Product::create(array_merge([
            'name' => $ad, 'unit_price' => 25, 'unit_type' => 'Paket', 'category' => 'Atıştırmalık',
            'location_id' => $konum?->id, 'stock_quantity' => $stok, 'critical_quantity' => $kritik,
            'is_active' => true,
        ], $ek));
    }

    /** @return array<string,mixed> */
    private function urunFormu(array $ek = []): array
    {
        return array_merge([
            'name' => 'Çikolatalı Gofret',
            'emoji' => '🍫',
            'category' => 'Atıştırmalık',
            'unit_price' => '12.50',
            'unit_type' => 'Paket',
            'description' => 'Fındıklı, şekerli; ığdır kayısılı',
            'is_active' => '1',
        ], $ek);
    }

    /** Kafe saatiyle verilen anda bir tuketim. */
    private function tuketim(User $ogrenci, Product $urun, string $yerelAn, int $adet = 1, array $ek = []): Consumption
    {
        return Consumption::create(array_merge([
            'user_id' => $ogrenci->id,
            'product_id' => $urun->id,
            'location_id' => ($urun->location ?? Location::selfService())->id,
            'quantity' => $adet,
            'unit_price' => $urun->unit_price,
            'consumed_at' => Carbon::parse($yerelAn, config('kafe.timezone'))->utc(),
        ], $ek));
    }

    private function openAiCevabi(array $icerik, int $durum = 200): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($icerik)]]],
            ], $durum),
        ]);
    }

    private function fotografYukleme(): void
    {
        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');
    }

    /** @return array{0:Location,1:Product,2:Product} */
    private function buzdolabi(): array
    {
        $dolap = $this->konum('Buzdolabı', 'fridge');
        $kola = $this->urun('Coca-Cola', $dolap, 18, 6);
        $ayran = $this->urun('Ayran', $dolap, null);

        return [$dolap, $kola, $ayran];
    }

    private function yukle(User $yonetici, Location $konum, string $tur = 'closing', int $adet = 1): string
    {
        $fotolar = [];
        for ($i = 1; $i <= $adet; $i++) {
            $fotolar[] = UploadedFile::fake()->image("raf-{$i}.jpg");
        }

        $this->actingAs($yonetici)->post(route('admin.stock.upload', $konum), [
            'record_type' => $tur,
            'photos' => $fotolar,
        ])->assertSessionHasNoErrors();

        return StockPhoto::latest('id')->first()->batch_id;
    }

    private function masa(string $ad, bool $acik = true): StudyTable
    {
        $masa = StudyTable::create(['name' => $ad]);
        if (! $acik) {
            $masa->update(['is_active' => false]);
        }

        return $masa;
    }

    // =========================================================================
    // Urunler
    // =========================================================================

    public function test_products_index_lists_products_with_location_stock_and_status(): void
    {
        $raf = $this->konum('Aburcubur Rafı');
        $this->urun('Ülker Çokoprens', $raf, 3, 5);
        $this->urun('Türk Kahvesi', null, null, null, ['category' => 'Sıcak İçecek']);
        $this->urun('Şalgam Suyu', null, 10, null, ['is_active' => false]);

        $this->actingAs($this->yonetici())->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('Ülker Çokoprens')
            ->assertSee('Aburcubur Rafı')
            ->assertSee('Türk Kahvesi')
            ->assertSee('takip yok')
            ->assertSee('Şalgam Suyu')
            ->assertSee('Pasif')
            ->assertSee('25,00 ₺')
            ->assertSee(route('admin.products.create'))
            ->assertSee(route('admin.stock.index'));
    }

    public function test_products_index_empty_state(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('Henüz ürün eklenmemiş.');
    }

    public function test_products_index_query_filters_work_with_turkish_text(): void
    {
        $this->urun('Çikolatalı Gofret');
        $this->urun('Ayran', null, null, null, ['category' => 'İçecek']);
        $this->urun('Eski Simit', null, null, null, ['is_active' => false]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.products.index', ['search' => 'Çikolata']))
            ->assertOk()->assertSee('Çikolatalı Gofret')->assertDontSee('Ayran');

        $this->actingAs($yonetici)->get(route('admin.products.index', ['category' => 'İçecek']))
            ->assertOk()->assertSee('Ayran')->assertDontSee('Çikolatalı Gofret');

        $this->actingAs($yonetici)->get(route('admin.products.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('Eski Simit')->assertDontSee('Ayran');
    }

    public function test_non_admins_cannot_reach_product_pages_and_guests_go_to_login(): void
    {
        $urun = $this->urun('Canga');

        foreach ([$this->ogrenci(), User::factory()->parent()->create(), User::factory()->create(['role' => 'coach'])] as $kisi) {
            $this->actingAs($kisi)->get(route('admin.products.index'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.products.create'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.products.edit', $urun))->assertForbidden();
            $this->actingAs($kisi)->post(route('admin.products.store'), $this->urunFormu())->assertForbidden();
            $this->actingAs($kisi)->put(route('admin.products.update', $urun), $this->urunFormu(['name' => 'Hack']))->assertForbidden();
            $this->actingAs($kisi)->post(route('admin.products.toggle-status', $urun))->assertForbidden();
            $this->actingAs($kisi)->delete(route('admin.products.destroy', $urun))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));

        $this->assertSame('Canga', $urun->fresh()->name);
        $this->assertTrue($urun->fresh()->is_active);
        $this->assertSame(1, Product::count());
    }

    public function test_product_create_page_offers_location_tags_but_not_the_system_location(): void
    {
        $this->konum('Buzdolabı');
        Location::selfService();
        $this->urun('Canga', null, null, null, ['unit_type' => 'Teneke Kutu']);

        $this->actingAs($this->yonetici())->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('Buzdolabı')
            ->assertDontSee('Self Adisyon')
            ->assertSee('name="new_location"', false)
            ->assertSee('name="stock_quantity"', false)
            ->assertSee('name="critical_quantity"', false)
            ->assertSee('Teneke Kutu');
    }

    public function test_product_store_saves_every_field_with_turkish_input(): void
    {
        $dolap = $this->konum('Buzdolabı');

        $this->actingAs($this->yonetici())
            ->post(route('admin.products.store'), $this->urunFormu([
                'location_id' => $dolap->id, 'stock_quantity' => '24', 'critical_quantity' => '5',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success', 'Ürün başarıyla oluşturuldu.');

        $urun = Product::sole();
        $this->assertSame('Çikolatalı Gofret', $urun->name);
        $this->assertSame('🍫', $urun->emoji);
        $this->assertSame('Atıştırmalık', $urun->category);
        $this->assertSame('12.50', $urun->unit_price);
        $this->assertSame('Paket', $urun->unit_type);
        $this->assertSame('Fındıklı, şekerli; ığdır kayısılı', $urun->description);
        $this->assertTrue($urun->is_active);
        $this->assertSame($dolap->id, $urun->location_id);
        $this->assertSame(24, $urun->stock_quantity);
        $this->assertSame(5, $urun->critical_quantity);
    }

    public function test_a_new_location_name_wins_over_the_select_and_reuses_an_existing_tag(): void
    {
        $dolap = $this->konum('Buzdolabı');
        $depo = $this->konum('Depo');
        $etiketSayisi = Location::count();
        $yonetici = $this->yonetici();

        // Yazilan ad, secilen konumdan once gelir; bosluklar kirpilir, ayni ad yeni etiket acmaz.
        $this->actingAs($yonetici)->post(route('admin.products.store'), $this->urunFormu([
            'location_id' => $dolap->id, 'new_location' => '  Depo ',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($depo->id, Product::sole()->location_id);
        $this->assertSame($etiketSayisi, Location::count());

        // Gercekten yeni bir ad yeni etiket acar.
        $this->actingAs($yonetici)->post(route('admin.products.store'), $this->urunFormu([
            'name' => 'Şeftali Suyu', 'new_location' => 'Kasa Arkası Dolap',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Kasa Arkası Dolap', Product::where('name', 'Şeftali Suyu')->sole()->location->name);
    }

    public function test_product_store_refuses_missing_and_invalid_fields(): void
    {
        $this->actingAs($this->yonetici())
            ->from(route('admin.products.create'))
            ->post(route('admin.products.store'), [
                'name' => '', 'unit_price' => '-3', 'unit_type' => '', 'location_id' => 99999,
                'stock_quantity' => '12.5',
            ])
            ->assertRedirect(route('admin.products.create'))
            ->assertSessionHasErrors(['name', 'unit_price', 'unit_type', 'location_id', 'stock_quantity']);

        $this->assertSame(0, Product::count());
    }

    public function test_product_edit_page_is_prefilled(): void
    {
        $dolap = $this->konum('Buzdolabı');
        $urun = $this->urun('Ayran', $dolap, 12, 3, ['description' => 'Yayık ayranı']);

        $this->actingAs($this->yonetici())->get(route('admin.products.edit', $urun))
            ->assertOk()
            ->assertSee('value="Ayran"', false)
            ->assertSee('Yayık ayranı')
            ->assertSee('value="12"', false)
            ->assertSee('value="3"', false)
            ->assertSee('<option value="' . $dolap->id . '" selected>', false)
            ->assertSee(route('admin.products.update', $urun));
    }

    public function test_product_update_changes_fields_clears_location_and_keeps_active_state(): void
    {
        $dolap = $this->konum('Buzdolabı');
        $urun = $this->urun('Ayran', $dolap, 12, 3);

        $form = $this->urunFormu([
            'name' => 'Yayık Ayranı', 'unit_price' => '17', 'location_id' => '',
            'stock_quantity' => '20', 'critical_quantity' => '4',
        ]);
        unset($form['is_active']);

        $this->actingAs($this->yonetici())->put(route('admin.products.update', $urun), $form)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success', 'Ürün başarıyla güncellendi.');

        $urun->refresh();
        $this->assertSame('Yayık Ayranı', $urun->name);
        $this->assertSame('17.00', $urun->unit_price);
        $this->assertNull($urun->location_id);
        $this->assertSame(20, $urun->stock_quantity);
        $this->assertSame(4, $urun->critical_quantity);
        $this->assertTrue($urun->is_active);
    }

    public function test_the_unchecked_active_box_takes_the_product_off_sale(): void
    {
        $urun = $this->urun('Ayran');

        // Form gizli is_active=0 + isaretsiz kutu gonderir.
        $this->actingAs($this->yonetici())->put(route('admin.products.update', $urun), $this->urunFormu(['is_active' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse($urun->fresh()->is_active);
    }

    public function test_lowering_stock_to_the_critical_level_notifies_admins_once(): void
    {
        $yonetici = $this->yonetici();
        $urun = $this->urun('Canga', null, 10, 5);

        $this->actingAs($yonetici)->put(route('admin.products.update', $urun), $this->urunFormu([
            'name' => 'Canga', 'stock_quantity' => '4', 'critical_quantity' => '5',
        ]))->assertSessionHasNoErrors();

        // Ayni kritik seviyede ikinci kayit yeniden uyarmaz.
        $this->actingAs($yonetici)->put(route('admin.products.update', $urun), $this->urunFormu([
            'name' => 'Canga', 'stock_quantity' => '3', 'critical_quantity' => '5',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Notification::where('user_id', $yonetici->id)->where('type', NotificationType::LowStock->value)->count());
    }

    public function test_toggle_status_flips_the_product_and_returns_to_the_list(): void
    {
        $urun = $this->urun('Ayran');
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.products.index'))
            ->post(route('admin.products.toggle-status', $urun))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success', 'Ürün pasifleştirildi.');
        $this->assertFalse($urun->fresh()->is_active);

        $this->actingAs($yonetici)->from(route('admin.products.index'))
            ->post(route('admin.products.toggle-status', $urun))
            ->assertSessionHas('success', 'Ürün aktifleştirildi.');
        $this->assertTrue($urun->fresh()->is_active);
    }

    public function test_an_unsold_product_can_be_deleted(): void
    {
        $urun = $this->urun('Eski Simit');

        $this->actingAs($this->yonetici())->delete(route('admin.products.destroy', $urun))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success', 'Ürün başarıyla silindi.');

        $this->assertModelMissing($urun);
    }

    public function test_deleting_a_sold_product_keeps_the_consumption_history(): void
    {
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Coca-Cola', null, null, null, ['unit_price' => 40]);
        $tuketim = $this->tuketim($ogrenci, $urun, '2026-09-28 15:00', 2);
        $this->assertEquals(80, $tuketim->total_price);

        // Beklenen: silme reddedilir (masalardaki gibi "pasife alin") ve
        // gecmis tuketim korunur. Once satir sessizce siliniyordu; ayin geliri,
        // ogrencinin adisyonu ve detay CSV'si geriye donuk degisiyordu.
        $this->actingAs($this->yonetici())->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $urun))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Pasifleştir'));

        $this->assertModelExists($urun);
        $this->assertModelExists($tuketim);
        $this->assertEquals(80, Consumption::inLocalMonth(2026, 9)->sum('total_price'));
    }

    public function test_opening_a_product_address_directly_does_not_crash(): void
    {
        $urun = $this->urun('Ayran');

        $yanit = $this->actingAs($this->yonetici())->get('/yonetim/urunler/' . $urun->id);

        $this->assertLessThan(500, $yanit->getStatusCode(), 'Kayitli bir GET rotasi 500 veriyor');
    }

    // =========================================================================
    // Stok sayfasi ve toplu giris
    // =========================================================================

    public function test_stock_page_shows_each_status_badge_and_the_critical_shortcut(): void
    {
        $raf = $this->konum('Aburcubur Rafı');
        $this->urun('Canga', $raf, 2, 5);        // kritik
        $this->urun('Dido', $raf, 0, 5);         // tukendi
        $this->urun('Nero', $raf, 30, 5);        // yeterli
        $this->urun('Türk Kahvesi', null, null); // takip yok

        $this->actingAs($this->yonetici())->get(route('admin.stock.index'))
            ->assertOk()
            ->assertSee('Kritik')
            ->assertSee('Tükendi')
            ->assertSee('Yeterli')
            ->assertSee('Takip yok')
            ->assertSee('2 ürün kritik')
            ->assertSee(route('admin.stock.index', ['durum' => 'critical']))
            ->assertSee('name="stok[' . Product::where('name', 'Canga')->value('id') . '][quantity]"', false)
            ->assertSee('Stokları kaydet');
    }

    public function test_stock_page_filters_by_every_status_and_by_location(): void
    {
        $raf = $this->konum('Aburcubur Rafı');
        $dolap = $this->konum('Buzdolabı');
        $this->urun('Canga', $raf, 2, 5);
        $this->urun('Dido', $raf, 0, 5);
        $this->urun('Nero', $raf, 30, 5);
        $this->urun('Türk Kahvesi', null, null);
        $this->urun('Coca-Cola', $dolap, 18, 6);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.stock.index', ['durum' => 'out']))
            ->assertOk()->assertSee('Dido')->assertDontSee('Canga')->assertDontSee('Nero');

        $this->actingAs($yonetici)->get(route('admin.stock.index', ['durum' => 'ok']))
            ->assertOk()->assertSee('Nero')->assertSee('Coca-Cola')->assertDontSee('Canga')->assertDontSee('Dido');

        $this->actingAs($yonetici)->get(route('admin.stock.index', ['durum' => 'untracked']))
            ->assertOk()->assertSee('Türk Kahvesi')->assertDontSee('Nero');

        $this->actingAs($yonetici)->get(route('admin.stock.index', ['konum' => $dolap->id, 'durum' => 'ok']))
            ->assertOk()->assertSee('Coca-Cola')->assertDontSee('Nero');
    }

    public function test_stock_page_with_an_empty_filter_result_hides_the_save_button(): void
    {
        $this->urun('Nero', null, 30, 5);

        $this->actingAs($this->yonetici())->get(route('admin.stock.index', ['durum' => 'out']))
            ->assertOk()
            ->assertSee('Bu süzgece uyan ürün yok.')
            ->assertDontSee('Stokları kaydet');
    }

    public function test_stock_page_refuses_a_nonsense_filter_without_crashing(): void
    {
        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.index'))
            ->get(route('admin.stock.index', ['durum' => 'hepsi', 'konum' => 'abc']))
            ->assertRedirect(route('admin.stock.index'))
            ->assertSessionHasErrors(['durum', 'konum']);
    }

    public function test_bulk_stock_entry_updates_only_changed_rows_and_reports_the_count(): void
    {
        $canga = $this->urun('Canga', null, 2, 5);
        $nero = $this->urun('Nero', null, 30, 5);
        $kahve = $this->urun('Türk Kahvesi', null, null);

        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.index'))
            ->post(route('admin.stock.update'), ['stok' => [
                $canga->id => ['quantity' => '40', 'critical' => '8'],
                $nero->id => ['quantity' => '30', 'critical' => '5'],   // degismedi
                $kahve->id => ['quantity' => '', 'critical' => ''],     // takip kapali kaldi
                99999 => ['quantity' => '5', 'critical' => ''],          // olmayan urun
            ]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stock.index'))
            ->assertSessionHas('success', '1 ürünün stoğu güncellendi.');

        $this->assertSame(40, $canga->fresh()->stock_quantity);
        $this->assertSame(8, $canga->fresh()->critical_quantity);
        $this->assertSame(30, $nero->fresh()->stock_quantity);
        $this->assertNull($kahve->fresh()->stock_quantity);
    }

    public function test_bulk_entry_that_drops_stock_below_critical_notifies_admins(): void
    {
        $yonetici = $this->yonetici();
        $nero = $this->urun('Nero', null, 30, 5);

        $this->actingAs($yonetici)->post(route('admin.stock.update'), ['stok' => [
            $nero->id => ['quantity' => '3', 'critical' => '5'],
        ]])->assertSessionHasNoErrors();

        $bildirim = Notification::where('user_id', $yonetici->id)->where('type', NotificationType::LowStock->value)->sole();
        $this->assertStringContainsString('Nero', $bildirim->title);
    }

    public function test_bulk_entry_refuses_negative_numbers_and_leaves_stock_untouched(): void
    {
        $canga = $this->urun('Canga', null, 2, 5);

        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.index'))
            ->post(route('admin.stock.update'), ['stok' => [$canga->id => ['quantity' => '-4', 'critical' => '-1']]])
            ->assertRedirect(route('admin.stock.index'))
            ->assertSessionHasErrors(["stok.{$canga->id}.quantity", "stok.{$canga->id}.critical"]);

        $this->assertSame(2, $canga->fresh()->stock_quantity);
    }

    public function test_bulk_entry_without_rows_is_refused(): void
    {
        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.index'))
            ->post(route('admin.stock.update'), [])
            ->assertSessionHasErrors('stok');
    }

    public function test_non_admins_cannot_reach_any_stock_route(): void
    {
        [$dolap, $kola] = $this->buzdolabi();
        $fark = DiscrepancyLog::create([
            'location_id' => $dolap->id, 'product_id' => $kola->id,
            'expected_quantity' => 18, 'actual_quantity' => 15, 'record_type' => 'closing',
        ]);
        $ogrenci = $this->ogrenci();
        $parti = (string) Str::uuid();

        $this->actingAs($ogrenci)->get(route('admin.stock.index'))->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.stock.update'), ['stok' => [$kola->id => ['quantity' => 0]]])->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.stock.counts'))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.stock.capture', $dolap))->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.stock.upload', $dolap), ['record_type' => 'opening'])->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.stock.analyze', [$dolap, $parti]))->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.stock.confirm', $dolap), [])->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.stock.discrepancy', $fark))->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.stock.resolve-discrepancy', $fark), ['resolution_notes' => 'x'])->assertForbidden();

        $this->assertSame(18, $kola->fresh()->stock_quantity);
        $this->assertFalse($fark->fresh()->resolved);
    }

    // =========================================================================
    // Sayim: sayfa, kayit, yukleme, analiz, onay
    // =========================================================================

    public function test_count_page_empty_state(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.stock.counts'))
            ->assertOk()
            ->assertSee('Henüz konum etiketi taşıyan ürün yok.')
            ->assertSee('Henüz stok kaydı bulunmuyor.')
            ->assertDontSee('⚠️ Çözülmemiş Tutarsızlıklar');
    }

    public function test_count_page_lists_tagged_locations_and_unresolved_discrepancies(): void
    {
        [$dolap, $kola] = $this->buzdolabi();
        $this->konum('Boş Raf'); // urunu olmayan etiket listelenmez
        $fark = DiscrepancyLog::create([
            'location_id' => $dolap->id, 'product_id' => $kola->id,
            'expected_quantity' => 18, 'actual_quantity' => 15, 'record_type' => 'closing',
        ]);

        $this->actingAs($this->yonetici())->get(route('admin.stock.counts'))
            ->assertOk()
            ->assertSee('Buzdolabı')
            ->assertSee('2 ürün')
            ->assertDontSee('Boş Raf')
            ->assertSee(route('admin.stock.capture', $dolap))
            ->assertSee('Çözülmemiş Tutarsızlıklar')
            ->assertSee('Eksik')
            ->assertSee('<td>-3</td>', false)
            ->assertSee(route('admin.stock.discrepancy', $fark));
    }

    public function test_count_page_still_opens_after_a_count_was_confirmed(): void
    {
        [$dolap, $kola] = $this->buzdolabi();
        $yonetici = $this->yonetici();

        // Onay, sayim sayfasina yonlendirir - yonetici bu sayfayi hemen gorur.
        $this->actingAs($yonetici)->followingRedirects()->post(route('admin.stock.confirm', $dolap), [
            'batch_id' => (string) Str::uuid(),
            'record_type' => 'closing',
            'products' => [['product_id' => $kola->id, 'verified_quantity' => 15]],
        ])
            ->assertOk()
            ->assertSee('Kapanış stok sayımı başarıyla kaydedildi.')
            ->assertSee('Son Stok Kayıtları')
            ->assertSee('Coca-Cola')
            ->assertSee('<td>15</td>', false)          // Miktar sutunu dolu olmali
            ->assertSee('Yönetici Şahin Öztürk');     // Kaydeden
    }

    public function test_capture_page_lists_the_location_products_and_preselects_the_type(): void
    {
        [$dolap] = $this->buzdolabi();

        $html = $this->actingAs($this->yonetici())->get(route('admin.stock.capture', [$dolap, 'type' => 'closing']))
            ->assertOk()
            ->assertSee('Stok Sayımı: Buzdolabı')
            ->assertSee('Bu Konumdaki Ürünler (2)')
            ->assertSee('Coca-Cola')
            ->assertSee('takip yok · ilk sayım başlatır')
            ->assertSee('<option value="closing" selected>', false)
            ->assertSee(route('admin.stock.upload', $dolap))
            ->getContent();

        $this->assertMatchesRegularExpression('/<td>Coca-Cola<\/td>\s*<td>\s*18\s*<\/td>/u', $html);
    }

    public function test_capture_page_for_a_location_without_products_renders(): void
    {
        $bos = $this->konum('Boş Raf');

        $this->actingAs($this->yonetici())->get(route('admin.stock.capture', $bos))
            ->assertOk()
            ->assertSee('Bu Konumdaki Ürünler (0)');
    }

    public function test_capture_page_for_a_missing_location_is_404(): void
    {
        $this->actingAs($this->yonetici())->get('/yonetim/stok/99999/kayit')->assertNotFound();
    }

    public function test_uploading_photos_stores_one_batch_and_opens_the_analysis(): void
    {
        $this->fotografYukleme();
        [$dolap] = $this->buzdolabi();
        $yonetici = $this->yonetici();

        $yanit = $this->actingAs($yonetici)->post(route('admin.stock.upload', $dolap), [
            'record_type' => 'opening',
            'photos' => [UploadedFile::fake()->image('sol.jpg'), UploadedFile::fake()->image('sag.png')],
        ]);

        $fotolar = StockPhoto::all();
        $this->assertCount(2, $fotolar);
        $this->assertCount(1, $fotolar->pluck('batch_id')->unique());
        $this->assertTrue(Str::isUuid($fotolar->first()->batch_id));
        $this->assertSame('opening', $fotolar->first()->record_type);
        $this->assertSame($yonetici->id, $fotolar->first()->admin_id);
        foreach ($fotolar as $foto) {
            Storage::disk('yukleme')->assertExists($foto->photo_path);
        }

        $yanit->assertRedirect(route('admin.stock.analyze', [$dolap, $fotolar->first()->batch_id]))
            ->assertSessionHas('success', '2 fotoğraf yüklendi. Analiz başlatılıyor...');
    }

    public function test_upload_refuses_missing_photos_non_images_and_unknown_types(): void
    {
        $this->fotografYukleme();
        [$dolap] = $this->buzdolabi();
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.stock.capture', $dolap))
            ->post(route('admin.stock.upload', $dolap), ['record_type' => 'opening'])
            ->assertRedirect(route('admin.stock.capture', $dolap))
            ->assertSessionHasErrors('photos');

        $this->actingAs($yonetici)->from(route('admin.stock.capture', $dolap))
            ->post(route('admin.stock.upload', $dolap), [
                'record_type' => 'ogle',
                'photos' => [UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf')],
            ])
            ->assertSessionHasErrors(['record_type', 'photos.0']);

        $this->assertSame(0, StockPhoto::count());
    }

    public function test_analysis_shows_ai_estimates_against_system_stock(): void
    {
        $this->fotografYukleme();
        config(['services.openai.api_key' => 'test-anahtari']);
        $this->openAiCevabi([
            'products_detected' => [
                ['product_id' => null, 'name' => 'Coca Cola', 'estimated_quantity' => 14, 'confidence' => 0.92],
                ['product_id' => null, 'name' => 'Şalgam', 'estimated_quantity' => 3, 'confidence' => 0.4],
            ],
            'anomalies' => [['type' => 'low_stock', 'description' => 'Kola rafı azalmış']],
            'overall_confidence' => 0.85,
            'summary' => 'Raf büyük ölçüde dolu.',
        ]);
        [$dolap, $kola, $ayran] = $this->buzdolabi();
        $yonetici = $this->yonetici();
        $parti = $this->yukle($yonetici, $dolap, 'closing');

        $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $parti]))
            ->assertOk()
            ->assertSee('Stok Analizi: Buzdolabı')
            ->assertSee('Kapanış')
            ->assertSee('1/1 fotoğraf başarıyla analiz edildi.')
            ->assertSee('Düşük Stok')
            ->assertSee('Kola rafı azalmış')
            ->assertSee('Beklenen ürün tespit edilemedi: Ayran')
            ->assertSee('name="batch_id" value="' . $parti . '"', false)
            ->assertSee('name="record_type" value="closing"', false)
            ->assertSee('name="products[' . $kola->id . '][verified_quantity]"', false)
            ->assertSee('value="14"', false)           // varsayilan: yapay zeka tahmini
            ->assertSee('-4')                          // 14 - 18
            ->assertSee('%92')
            ->assertSee('Eşleştirilemeyen Tespitler')
            ->assertSee('Şalgam');

        $this->assertNotNull(StockPhoto::sole()->processed_at);
        Http::assertSentCount(1);
    }

    public function test_analysis_without_an_api_key_still_offers_manual_counting(): void
    {
        $this->fotografYukleme();
        config(['services.openai.api_key' => null]);
        Http::fake();
        [$dolap, $kola] = $this->buzdolabi();
        $yonetici = $this->yonetici();
        $parti = $this->yukle($yonetici, $dolap);

        $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $parti]))
            ->assertOk()
            ->assertSee('Yapay zeka analizi tamamlanamadı.')
            ->assertSee('Adetleri elle girerek sayımı yine de kaydedebilirsiniz.')
            ->assertSee('name="products[' . $kola->id . '][verified_quantity]"', false);

        Http::assertNothingSent();
    }

    public function test_analysis_survives_an_openai_error(): void
    {
        $this->fotografYukleme();
        config(['services.openai.api_key' => 'test-anahtari']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);
        [$dolap] = $this->buzdolabi();
        $yonetici = $this->yonetici();
        $parti = $this->yukle($yonetici, $dolap);

        $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $parti]))
            ->assertOk()
            ->assertSee('Yapay zeka analizi tamamlanamadı.')
            ->assertSee('Sayımı Onayla ve Kaydet');
    }

    public function test_analysis_of_an_unknown_or_foreign_batch_goes_back_to_capture(): void
    {
        $this->fotografYukleme();
        config(['services.openai.api_key' => null]);
        [$dolap] = $this->buzdolabi();
        $raf = $this->konum('Aburcubur Rafı');
        $this->urun('Canga', $raf, 10);
        $yonetici = $this->yonetici();
        $rafPartisi = $this->yukle($yonetici, $raf);

        $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, (string) Str::uuid()]))
            ->assertRedirect(route('admin.stock.capture', $dolap))
            ->assertSessionHas('error', 'Fotoğraflar bulunamadı.');

        // Baska konumun partisi bu konumun analizine acilmaz.
        $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $rafPartisi]))
            ->assertRedirect(route('admin.stock.capture', $dolap));
    }

    public function test_confirming_the_review_form_records_counts_updates_stock_and_logs_the_difference(): void
    {
        [$dolap, $kola, $ayran] = $this->buzdolabi();
        $yonetici = $this->yonetici();
        $parti = (string) Str::uuid();

        // Inceleme formunun gonderdigi bicim: products[<id>][...]
        $this->actingAs($yonetici)->post(route('admin.stock.confirm', $dolap), [
            'batch_id' => $parti,
            'record_type' => 'closing',
            'products' => [
                $kola->id => ['product_id' => $kola->id, 'ai_suggested_quantity' => '14', 'ai_confidence' => '0.92',
                    'verified_quantity' => '15', 'notes' => 'Bir kasa arkada kalmış'],
                $ayran->id => ['product_id' => $ayran->id, 'ai_suggested_quantity' => '', 'ai_confidence' => '',
                    'verified_quantity' => '9', 'notes' => ''],
            ],
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stock.counts'))
            ->assertSessionHas('success', 'Kapanış stok sayımı başarıyla kaydedildi.');

        $this->assertSame(15, $kola->fresh()->stock_quantity);
        $this->assertSame(9, $ayran->fresh()->stock_quantity); // ilk sayim takibi baslatti

        $kayit = StockRecord::where('product_id', $kola->id)->sole();
        $this->assertSame(15, (int) $kayit->verified_quantity);
        $this->assertSame(14, (int) $kayit->ai_suggested_quantity);
        $this->assertSame('0.92', $kayit->ai_confidence);
        $this->assertSame('Bir kasa arkada kalmış', $kayit->notes);
        $this->assertSame($yonetici->id, $kayit->admin_id);
        $this->assertSame(2, StockRecord::count());

        $fark = DiscrepancyLog::sole();
        $this->assertSame($kola->id, $fark->product_id);
        $this->assertSame(-3, $fark->difference);
        $this->assertSame('closing', $fark->record_type);
        $this->assertFalse($fark->resolved);
    }

    public function test_confirm_refuses_negative_or_missing_counts(): void
    {
        [$dolap, $kola] = $this->buzdolabi();

        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.counts'))
            ->post(route('admin.stock.confirm', $dolap), [
                'batch_id' => (string) Str::uuid(),
                'record_type' => 'closing',
                'products' => [$kola->id => ['product_id' => $kola->id, 'verified_quantity' => '-2']],
            ])
            ->assertSessionHasErrors("products.{$kola->id}.verified_quantity");

        $this->actingAs($this->yonetici())
            ->from(route('admin.stock.counts'))
            ->post(route('admin.stock.confirm', $dolap), ['batch_id' => (string) Str::uuid(), 'record_type' => 'closing'])
            ->assertSessionHasErrors('products');

        $this->assertSame(18, $kola->fresh()->stock_quantity);
        $this->assertSame(0, StockRecord::count());
    }

    public function test_the_whole_count_flow_from_capture_to_confirmation(): void
    {
        $this->fotografYukleme();
        config(['services.openai.api_key' => 'test-anahtari']);
        $this->openAiCevabi([
            'products_detected' => [['product_id' => null, 'name' => 'Coca-Cola', 'estimated_quantity' => 16, 'confidence' => 0.9]],
            'anomalies' => [],
            'overall_confidence' => 0.9,
            'summary' => 'ok',
        ]);
        [$dolap, $kola, $ayran] = $this->buzdolabi();
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.stock.counts'))->assertOk()->assertSee(route('admin.stock.capture', $dolap));
        $this->actingAs($yonetici)->get(route('admin.stock.capture', $dolap))->assertOk();
        $parti = $this->yukle($yonetici, $dolap, 'opening', 2);

        $html = $this->actingAs($yonetici)->get(route('admin.stock.analyze', [$dolap, $parti]))->assertOk()->getContent();

        // Inceleme formundaki gizli alanlari ve varsayilan adetleri aynen geri gonder.
        preg_match_all('/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/s', $html, $eslesme, PREG_SET_ORDER);
        $form = [];
        foreach ($eslesme as [$tum, $ad, $deger]) {
            if (str_starts_with($ad, 'products[') || in_array($ad, ['batch_id', 'record_type', '_token'], true)) {
                $form[$ad] = html_entity_decode($deger);
            }
        }
        parse_str(http_build_query($form), $gonderilen);
        $this->assertArrayHasKey('products', $gonderilen);

        $this->actingAs($yonetici)->post(route('admin.stock.confirm', $dolap), $gonderilen)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stock.counts'));

        $this->assertSame(16, $kola->fresh()->stock_quantity);
        $this->assertSame(0, $ayran->fresh()->stock_quantity); // takip yoktu, AI gormedi, varsayilan 0
        $this->assertSame(1, DiscrepancyLog::count());
        Http::assertSentCount(2); // iki fotograf, her biri bir kez
    }

    // =========================================================================
    // Tutarsizlik
    // =========================================================================

    private function tutarsizlik(array $ek = []): DiscrepancyLog
    {
        [$dolap, $kola] = $this->buzdolabi();

        return DiscrepancyLog::create(array_merge([
            'location_id' => $dolap->id, 'product_id' => $kola->id,
            'expected_quantity' => 18, 'actual_quantity' => 15, 'record_type' => 'closing',
        ], $ek));
    }

    public function test_discrepancy_page_shows_the_numbers_links_and_the_resolve_form(): void
    {
        $fark = $this->tutarsizlik();

        $this->actingAs($this->yonetici())->get(route('admin.stock.discrepancy', $fark))
            ->assertOk()
            ->assertSee('Tutarsızlık #' . $fark->id)
            ->assertSee('Coca-Cola')
            ->assertSee('Buzdolabı')
            ->assertSee('Eksik')
            ->assertSee('Kapanış')
            ->assertSee('Bekliyor')
            ->assertSee(route('admin.products.edit', $fark->product_id))
            ->assertSee(route('admin.stock.index', ['konum' => $fark->location_id]))
            ->assertSee(route('admin.stock.resolve-discrepancy', $fark));
    }

    public function test_resolving_a_discrepancy_records_who_and_why_and_clears_it_from_the_count_page(): void
    {
        $fark = $this->tutarsizlik();
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->post(route('admin.stock.resolve-discrepancy', $fark), [
            'resolution_notes' => 'Üç şişe kırılmış, çöpe atıldı; tutanak tutuldu.',
        ])
            ->assertRedirect(route('admin.stock.counts'))
            ->assertSessionHas('success', 'Tutarsızlık çözümlendi.');

        $fark->refresh();
        $this->assertTrue($fark->resolved);
        $this->assertSame($yonetici->id, $fark->resolved_by);
        $this->assertNotNull($fark->resolved_at);

        $this->actingAs($yonetici)->get(route('admin.stock.counts'))
            ->assertOk()->assertDontSee('⚠️ Çözülmemiş Tutarsızlıklar');

        $this->actingAs($yonetici)->get(route('admin.stock.discrepancy', $fark))
            ->assertOk()
            ->assertSee('Çözümlendi')
            ->assertSee('Yönetici Şahin Öztürk')
            ->assertSee('Üç şişe kırılmış, çöpe atıldı; tutanak tutuldu.')
            ->assertDontSee(route('admin.stock.resolve-discrepancy', $fark));
    }

    public function test_a_resolved_discrepancy_note_cannot_be_overwritten(): void
    {
        $fark = $this->tutarsizlik();
        $ilk = $this->yonetici('Yönetici Ali');
        $ikinci = $this->yonetici('Yönetici Veli');

        $this->actingAs($ilk)->post(route('admin.stock.resolve-discrepancy', $fark), ['resolution_notes' => 'Kırık şişe']);
        $cozumAni = $fark->fresh()->resolved_at;

        $this->travel(10)->minutes();
        // Eski sekmeden ikinci gonderim (ya da cift tiklama): reddedilir ve
        // yonetici mevcut notu gorsun diye tutarsizlik sayfasina doner.
        $this->actingAs($ikinci)->post(route('admin.stock.resolve-discrepancy', $fark), ['resolution_notes' => 'Sayım hatası'])
            ->assertRedirect(route('admin.stock.discrepancy', $fark))
            ->assertSessionHas('error', 'Bu tutarsızlık zaten çözümlenmiş; not değiştirilemez.');

        // Sayfa "Bu not kayda işlenir ve daha sonra değiştirilemez" diyor.
        $fark->refresh();
        $this->assertSame('Kırık şişe', $fark->resolution_notes);
        $this->assertSame($ilk->id, $fark->resolved_by);
        $this->assertEquals($cozumAni, $fark->resolved_at);
    }

    public function test_missing_discrepancy_is_404(): void
    {
        $this->actingAs($this->yonetici())->get('/yonetim/stok/tutarsizlik/99999')->assertNotFound();
    }

    // =========================================================================
    // Masalar
    // =========================================================================

    public function test_tables_index_lists_seats_in_number_order_with_actions(): void
    {
        $this->masa('Masa 10');
        $this->masa('Masa 2');
        $kapali = $this->masa('Sessiz Oda Masa 1-A', false);

        $html = $this->actingAs($this->yonetici())->get(route('admin.tables.index'))
            ->assertOk()
            ->assertSeeInOrder(['Masa 2', 'Masa 10'])
            ->assertSee('Sessiz Oda Masa 1-A')
            ->assertSee('Kapalı')
            ->assertSee($kapali->qr_code)
            ->assertSee(route('admin.tables.qr', $kapali))
            ->assertSee(route('admin.tables.edit', $kapali))
            ->assertSee(route('admin.tables.toggle-status', $kapali))
            ->assertSee(route('admin.tables.print-qr'))
            ->getContent();

        // Kapali masanin dugmesi "Aç", acik masalarinki "Kapat".
        $this->assertMatchesRegularExpression('/>\s*Aç\s*</u', $html);
        $this->assertMatchesRegularExpression('/>\s*Kapat\s*</u', $html);
    }

    public function test_tables_index_empty_state(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.tables.index'))
            ->assertOk()
            ->assertSee('Henüz masa yok');
    }

    public function test_table_create_page_and_store_with_turkish_name(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.tables.create'))
            ->assertOk()
            ->assertSee(route('admin.tables.store'));

        $this->actingAs($yonetici)->post(route('admin.tables.store'), ['name' => 'Çalışma Salonu Masa 3-Ş'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.tables.index'))
            ->assertSessionHas('success', 'Masa oluşturuldu. QR kodu yazdırmayı unutmayın.');

        $masa = StudyTable::sole();
        $this->assertSame('Çalışma Salonu Masa 3-Ş', $masa->name);
        $this->assertTrue($masa->is_active);
        $this->assertMatchesRegularExpression('/^MASA-[A-Z0-9]{8}$/', $masa->qr_code);
    }

    public function test_table_store_validates_the_name(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.tables.create'))
            ->post(route('admin.tables.store'), ['name' => ''])
            ->assertRedirect(route('admin.tables.create'))
            ->assertSessionHasErrors('name');

        $this->actingAs($yonetici)->from(route('admin.tables.create'))
            ->post(route('admin.tables.store'), ['name' => str_repeat('ş', 51)])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, StudyTable::count());
    }

    public function test_table_edit_page_shows_the_fixed_qr_code(): void
    {
        $masa = $this->masa('Masa 5');

        $this->actingAs($this->yonetici())->get(route('admin.tables.edit', $masa))
            ->assertOk()
            ->assertSee('Masa: Masa 5')
            ->assertSee('value="' . $masa->qr_code . '" readonly', false)
            ->assertSee('name="is_active"', false)
            ->assertSee(route('admin.tables.qr', $masa));
    }

    public function test_table_update_renames_closes_and_keeps_the_qr_code(): void
    {
        $masa = $this->masa('Masa 5');
        $kod = $masa->qr_code;

        // Kutu isaretsizse alan hic gelmez: masa kapanir.
        $this->actingAs($this->yonetici())->put(route('admin.tables.update', $masa), ['name' => 'Pencere Önü Masa 5', 'qr_code' => 'HACK'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.tables.index'))
            ->assertSessionHas('success', 'Masa güncellendi.');

        $masa->refresh();
        $this->assertSame('Pencere Önü Masa 5', $masa->name);
        $this->assertFalse($masa->is_active);
        $this->assertSame($kod, $masa->qr_code);

        $this->actingAs($this->yonetici())->put(route('admin.tables.update', $masa), ['name' => 'Pencere Önü Masa 5', 'is_active' => '1']);
        $this->assertTrue($masa->fresh()->is_active);
    }

    public function test_table_toggle_status_flips_and_goes_back(): void
    {
        $masa = $this->masa('Masa 7');

        $this->actingAs($this->yonetici())->from(route('admin.tables.index'))
            ->post(route('admin.tables.toggle-status', $masa))
            ->assertRedirect(route('admin.tables.index'))
            ->assertSessionHas('success', 'Masa durumu güncellendi.');
        $this->assertFalse($masa->fresh()->is_active);

        $this->actingAs($this->yonetici())->post(route('admin.tables.toggle-status', $masa));
        $this->assertTrue($masa->fresh()->is_active);
    }

    public function test_an_unused_table_is_deleted_but_one_with_history_is_kept(): void
    {
        $bos = $this->masa('Masa 30');
        $dolu = $this->masa('Masa 31');
        StudySession::create([
            'student_id' => $this->ogrenci()->id,
            'study_table_id' => $dolu->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHour(),
            'duration_minutes' => 120,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->delete(route('admin.tables.destroy', $bos))
            ->assertRedirect(route('admin.tables.index'))
            ->assertSessionHas('success', 'Masa silindi.');
        $this->assertModelMissing($bos);

        $this->actingAs($yonetici)->from(route('admin.tables.edit', $dolu))
            ->delete(route('admin.tables.destroy', $dolu))
            ->assertRedirect(route('admin.tables.edit', $dolu))
            ->assertSessionHas('error', 'Bu masada çalışma kaydı var; silinemez. Masayı kapatabilirsiniz.');
        $this->assertModelExists($dolu);
    }

    public function test_table_qr_page_shows_the_code_and_the_scan_address(): void
    {
        $masa = $this->masa('Masa 12');

        $this->actingAs($this->yonetici())->get(route('admin.tables.qr', $masa))
            ->assertOk()
            ->assertSee('QR Kod: Masa 12')
            ->assertSee($masa->qr_code)
            ->assertSee($masa->qr_url)
            ->assertSee('api.qrserver.com', false)
            ->assertDontSee('henüz yayında değil');
    }

    public function test_print_sheet_covers_open_tables_only_and_has_an_empty_state(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.tables.print-qr'))
            ->assertOk()
            ->assertSee('Yazdırılacak masa yok');

        $this->masa('Masa 1');
        $this->masa('Masa 2');
        $this->masa('Masa 3', false);

        $this->actingAs($yonetici)->get(route('admin.tables.print-qr'))
            ->assertOk()
            ->assertSee('<strong>2</strong> masanın QR kodu yazdırılmaya hazır.', false)
            ->assertSeeInOrder(['Masa 1', 'Masa 2'])
            ->assertDontSee('Masa 3 QR kodu');
    }

    public function test_non_admins_cannot_reach_table_management(): void
    {
        $masa = $this->masa('Masa 1');
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->get(route('admin.tables.index'))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.tables.create'))->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.tables.store'), ['name' => 'Benim Masam'])->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.tables.edit', $masa))->assertForbidden();
        $this->actingAs($ogrenci)->put(route('admin.tables.update', $masa), ['name' => 'X'])->assertForbidden();
        $this->actingAs($ogrenci)->post(route('admin.tables.toggle-status', $masa))->assertForbidden();
        $this->actingAs($ogrenci)->delete(route('admin.tables.destroy', $masa))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.tables.qr', $masa))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('admin.tables.print-qr'))->assertForbidden();

        $this->assertSame('Masa 1', $masa->fresh()->name);
        $this->assertTrue($masa->fresh()->is_active);
        $this->assertSame(1, StudyTable::count());
    }

    // =========================================================================
    // Raporlar
    // =========================================================================

    public function test_reports_index_empty_state(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('0,00 ₺')
            ->assertSee('Henüz fatura oluşturulmamış.')
            ->assertSee('Bu ay tüketim verisi yok.');
    }

    public function test_reports_index_sums_this_local_month_and_ranks_consumers(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz');
        $ismail = $this->ogrenci('İsmail Çağlar');
        $kola = $this->urun('Coca-Cola', null, null, null, ['unit_price' => 40]);
        $simit = $this->urun('Simit', null, null, null, ['unit_price' => 15]);

        $this->tuketim($ayse, $kola, '2026-09-28 15:00', 2);       // 80
        $this->tuketim($ismail, $simit, '2026-09-01 00:30', 1);    // 15 (yerel 1 Eylul, UTC 31 Agustos)
        $this->tuketim($ismail, $kola, '2026-08-31 23:30', 1);     // Agustos - sayilmaz
        $this->tuketim($ayse, $simit, '2026-09-29 10:00', 1, ['is_undone' => true]); // geri alindi

        $this->actingAs($this->yonetici())->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('95,00 ₺')           // bu ay gelir
            ->assertSee('47,50 ₺')           // kisi basi
            ->assertSeeInOrder(['Ayşe Yılmaz', '80,00 ₺', 'İsmail Çağlar', '15,00 ₺'])
            ->assertSee(route('admin.reports.monthly', ['year' => 2026, 'month' => 9]))
            ->assertSee(route('admin.reports.export-summary', ['year' => 2026, 'month' => 9]))
            ->assertSee(route('admin.reports.export-detailed', ['year' => 2026, 'month' => 9]));
    }

    public function test_reports_index_shows_recent_bill_status_in_turkish(): void
    {
        MonthlyBill::create([
            'user_id' => $this->ogrenci('Ayşe Yılmaz')->id, 'bill_month' => '2026-08-01',
            'total_items' => 3, 'total_amount' => 60, 'status' => 'pending',
        ]);

        $this->actingAs($this->yonetici())->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('Ağustos 2026')
            ->assertSee('Beklemede')
            ->assertDontSee('Pending');
    }

    public function test_generating_bills_totals_the_local_month_and_is_idempotent(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz');
        $kola = $this->urun('Coca-Cola', null, null, null, ['unit_price' => 40]);
        $this->tuketim($ayse, $kola, '2026-09-01 00:30', 2);   // yerel Eylul
        $this->tuketim($ayse, $kola, '2026-08-31 23:59', 5);   // Agustos
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 9])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.reports.monthly', ['year' => 2026, 'month' => 9]))
            ->assertSessionHas('success', '1 fatura oluşturuldu.');

        // Cift gonderim ikinci fatura acmaz.
        $this->actingAs($yonetici)->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 9]);

        $fatura = MonthlyBill::sole();
        $this->assertSame($ayse->id, $fatura->user_id);
        $this->assertSame(2, (int) $fatura->total_items);
        $this->assertEquals(80, $fatura->total_amount);
        $this->assertSame('2026-09-01', $fatura->bill_month->toDateString());
    }

    public function test_generating_bills_validates_the_period(): void
    {
        $this->actingAs($this->yonetici())->from(route('admin.reports.index'))
            ->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 13])
            ->assertRedirect(route('admin.reports.index'))
            ->assertSessionHasErrors('month');

        $this->assertSame(0, MonthlyBill::count());
    }

    public function test_generating_last_months_bills_includes_a_student_suspended_since(): void
    {
        $borclu = $this->ogrenci('Borçlu Öğrenci');
        $kola = $this->urun('Coca-Cola', null, null, null, ['unit_price' => 40]);
        $this->tuketim($borclu, $kola, '2026-08-20 15:00', 3);   // 120 TL, Agustos
        $borclu->update(['subscription_status' => 'suspended']);  // odemedi, askiya alindi

        $this->actingAs($this->yonetici())->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 8]);

        $fatura = MonthlyBill::where('user_id', $borclu->id)->first();
        $this->assertNotNull($fatura, 'Agustosta 120 TL tuketen ogrenciye Agustos faturasi cikmadi');
        $this->assertEquals(120, $fatura->total_amount);
    }

    public function test_monthly_report_lists_bills_with_user_links_and_totals(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz', ['email' => 'ayse@example.com']);
        MonthlyBill::create([
            'user_id' => $ayse->id, 'bill_month' => '2026-09-01',
            'total_items' => 4, 'total_amount' => 110, 'package_amount' => 1500, 'status' => 'sent',
        ]);

        $this->actingAs($this->yonetici())->get(route('admin.reports.monthly', ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertSee('Aylık Rapor: Eylül 2026')
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('ayse@example.com')
            ->assertSee('110,00 ₺')
            ->assertSee('1.610,00 ₺')
            ->assertSee('Gönderildi')
            ->assertSee(route('admin.reports.user', ['user' => $ayse, 'year' => 2026, 'month' => 9]))
            ->assertSee(route('admin.reports.export-summary', ['year' => 2026, 'month' => 9]));
    }

    public function test_monthly_report_empty_and_nonsense_periods_do_not_crash(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.reports.monthly', ['year' => 2025, 'month' => 2]))
            ->assertOk()
            ->assertSee('Şubat 2025 için fatura bulunamadı.');

        $this->actingAs($yonetici)->get('/yonetim/raporlar/aylik?year=abc&month=-1')
            ->assertOk()
            ->assertSee('Eylül 2026');
    }

    public function test_report_pages_default_to_the_local_month_right_after_midnight_on_the_first(): void
    {
        // Yerel 1 Ekim 01:00 = UTC 30 Eylul 22:00
        $this->travelTo(Carbon::parse('2026-10-01 01:00', config('kafe.timezone')));
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->get(route('admin.reports.monthly'))
            ->assertOk()
            ->assertSee('Aylık Rapor: Ekim 2026');

        // Ozet kartlari Ekim'i gosteriyor; baglantilar da Ekim'e gitmeli.
        $this->actingAs($yonetici)->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee(route('admin.reports.monthly', ['year' => 2026, 'month' => 10]));
    }

    public function test_summary_csv_downloads_with_turkish_names(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz', ['email' => 'ayse@example.com']);
        MonthlyBill::create([
            'user_id' => $ayse->id, 'bill_month' => '2026-09-01',
            'total_items' => 4, 'total_amount' => 110, 'package_amount' => 1500, 'status' => 'pending',
        ]);

        $yanit = $this->actingAs($this->yonetici())->get(route('admin.reports.export-summary', ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="kral-kafe-ozet-2026-9.csv"');

        $satirlar = array_map('str_getcsv', array_filter(explode("\n", $yanit->getContent())));
        $this->assertSame('İsim', $satirlar[0][1]);
        $this->assertSame([(string) $ayse->id, 'Ayşe Yılmaz', 'ayse@example.com', '4', '110.00', '1500.00', '1610.00'], $satirlar[1]);
    }

    public function test_detailed_csv_uses_local_time_and_the_local_month(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz');
        $raf = $this->konum('Aburcubur Rafı');
        $kola = $this->urun('Coca-Cola', $raf, null, null, ['unit_price' => 40]);
        $simit = $this->urun('Simit', null, null, null, ['unit_price' => 15]);
        $this->tuketim($ayse, $kola, '2026-09-01 00:30', 2);   // Eylul (UTC'de Agustos)
        $this->tuketim($ayse, $simit, '2026-10-01 00:30', 1);  // Ekim (UTC'de Eylul)

        $yanit = $this->actingAs($this->yonetici())->get(route('admin.reports.export-detailed', ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kral-kafe-detay-2026-9.csv"');

        $satirlar = array_map('str_getcsv', array_values(array_filter(explode("\n", $yanit->getContent()))));
        $this->assertCount(2, $satirlar);
        $this->assertSame(['01.09.2026 00:30', 'Ayşe Yılmaz', 'Coca-Cola', 'Aburcubur Rafı', '2', '40.00', '80.00'], $satirlar[1]);
    }

    public function test_csv_exports_keep_columns_when_a_name_contains_a_quote(): void
    {
        $ogrenci = $this->ogrenci('Ali "Kral" Öztürk');
        $urun = $this->urun('Tost "Karışık", büyük', null, null, null, ['unit_price' => 60]);
        $this->tuketim($ogrenci, $urun, '2026-09-10 12:00', 1);
        MonthlyBill::generateForUserMonth($ogrenci, 2026, 9);
        $yonetici = $this->yonetici();

        $detay = $this->actingAs($yonetici)->get(route('admin.reports.export-detailed', ['year' => 2026, 'month' => 9]))->getContent();
        $satir = str_getcsv(array_values(array_filter(explode("\n", $detay)))[1]);
        $this->assertCount(7, $satir);
        $this->assertSame('Ali "Kral" Öztürk', $satir[1]);
        $this->assertSame('Tost "Karışık", büyük', $satir[2]);

        $ozet = $this->actingAs($yonetici)->get(route('admin.reports.export-summary', ['year' => 2026, 'month' => 9]))->getContent();
        $satir = str_getcsv(array_values(array_filter(explode("\n", $ozet)))[1]);
        $this->assertCount(7, $satir);
        $this->assertSame('Ali "Kral" Öztürk', $satir[1]);
    }

    public function test_csv_exports_of_an_empty_month_have_only_the_header(): void
    {
        $yonetici = $this->yonetici();

        $ozet = $this->actingAs($yonetici)->get(route('admin.reports.export-summary', ['year' => 2024, 'month' => 1]))->assertOk()->getContent();
        $detay = $this->actingAs($yonetici)->get(route('admin.reports.export-detailed', ['year' => 'x', 'month' => 'y']))->assertOk()->getContent();

        $this->assertSame(1, substr_count(trim($ozet), "\n") + 1);
        $this->assertSame(1, substr_count(trim($detay), "\n") + 1);
    }

    public function test_user_report_breaks_down_by_product_day_and_row(): void
    {
        $ayse = $this->ogrenci('Ayşe Yılmaz', ['phone' => '0532 123 45 67']);
        $raf = $this->konum('Aburcubur Rafı');
        $kola = $this->urun('Coca-Cola', $raf, null, null, ['unit_price' => 40, 'emoji' => '🥤']);
        $simit = $this->urun('Simit', null, null, null, ['unit_price' => 15]);
        $this->tuketim($ayse, $kola, '2026-09-01 00:30', 2);   // yerel 1 Eylul
        $this->tuketim($ayse, $simit, '2026-09-01 18:00', 1);
        $this->tuketim($ayse, $kola, '2026-09-15 16:00', 1);
        $this->tuketim($ayse, $simit, '2026-09-16 16:00', 1, ['is_undone' => true]);

        $this->actingAs($this->yonetici())->get(route('admin.reports.user', ['user' => $ayse, 'year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertSee('Ayşe Yılmaz - Eylül 2026')
            ->assertSee('135,00 ₺')                 // 80 + 15 + 40
            ->assertSee('01.09.2026')
            ->assertSee('15.09.2026')
            ->assertDontSee('16.09.2026')
            ->assertSee('01.09.2026 00:30')
            ->assertSee('Aburcubur Rafı')
            ->assertSee('Self Adisyon')
            ->assertSee('3 kayıt')
            ->assertSee(route('admin.users.edit', $ayse))
            ->assertSee(route('admin.reports.monthly', ['year' => 2026, 'month' => 9]));
    }

    public function test_user_report_for_a_user_without_consumption_or_email(): void
    {
        $veli = User::factory()->parent()->create(['name' => 'Hatice Doğan']);

        $this->actingAs($this->yonetici())->get(route('admin.reports.user', $veli))
            ->assertOk()
            ->assertSee('Hatice Doğan - Eylül 2026')
            ->assertSee('Bu dönemde ürün tüketimi bulunamadı.')
            ->assertSee('Eylül 2026 için tüketim kaydı bulunamadı.');

        $this->actingAs($this->yonetici())->get('/yonetim/raporlar/kullanici/99999')->assertNotFound();
    }

    public function test_non_admins_cannot_reach_reports_or_exports(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = User::factory()->create(['role' => 'coach']);

        foreach ([$ogrenci, $koc] as $kisi) {
            $this->actingAs($kisi)->get(route('admin.reports.index'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.reports.monthly'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.reports.export-summary'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.reports.export-detailed'))->assertForbidden();
            $this->actingAs($kisi)->get(route('admin.reports.user', $ogrenci))->assertForbidden();
            $this->actingAs($kisi)->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 9])->assertForbidden();
        }

        $this->assertSame(0, MonthlyBill::count());
    }
}
