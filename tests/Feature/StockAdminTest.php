<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Dalga 29 - Urunler altinda stok: urun formu, stok sayfasi, sayim.
 *
 * Lokasyonlar sayfasi kalkti; konum urunun formunda secilen (ya da yazilan)
 * bir etiket.
 */
class StockAdminTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function urun(string $ad, ?Location $konum = null, ?int $stok = null, ?int $kritik = null): Product
    {
        return Product::create([
            'name' => $ad, 'unit_price' => 20, 'unit_type' => 'Paket',
            'location_id' => $konum?->id, 'stock_quantity' => $stok, 'critical_quantity' => $kritik,
        ]);
    }

    /** @return array<string,mixed> */
    private function form(array $ek = []): array
    {
        return array_merge([
            'name' => 'Canga', 'unit_price' => 27, 'unit_type' => 'Paket', 'is_active' => 1,
        ], $ek);
    }

    // --- Urun formu ----------------------------------------------------------

    /** HATA: aciklama formda vardi ama hic kaydedilmiyordu. */
    public function test_the_description_is_saved_and_shown_on_edit(): void
    {
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->post(route('admin.products.store'), $this->form(['description' => 'Fındıklı gofret']));

        $urun = Product::sole();
        $this->assertSame('Fındıklı gofret', $urun->description);
        $this->actingAs($yonetici)->get(route('admin.products.edit', $urun))->assertSee('Fındıklı gofret');
    }

    public function test_the_description_is_updated(): void
    {
        $urun = $this->urun('Canga');

        $this->actingAs($this->yonetici())->put(route('admin.products.update', $urun), $this->form(['description' => 'Yeni tarif']));

        $this->assertSame('Yeni tarif', $urun->fresh()->description);
    }

    /** HATA: duzenleme formunda durum kutusu yoktu; her kayit urunu pasife aliyordu. */
    public function test_editing_keeps_the_product_active(): void
    {
        $urun = $this->urun('Canga');
        $form = $this->form();
        unset($form['is_active']);

        $this->actingAs($this->yonetici())->put(route('admin.products.update', $urun), $form);

        $this->assertTrue($urun->fresh()->is_active);
    }

    public function test_the_edit_form_offers_the_active_switch(): void
    {
        $urun = $this->urun('Canga');

        $this->actingAs($this->yonetici())->get(route('admin.products.edit', $urun))
            ->assertOk()
            ->assertSee('name="is_active"', false);
    }

    public function test_stock_and_critical_level_are_entered_on_the_product(): void
    {
        $dolap = Location::create(['name' => 'Aburcubur Rafı']);

        $this->actingAs($this->yonetici())->post(route('admin.products.store'), $this->form([
            'location_id' => $dolap->id, 'stock_quantity' => 24, 'critical_quantity' => 5,
        ]));

        $urun = Product::sole();
        $this->assertSame($dolap->id, $urun->location_id);
        $this->assertSame(24, $urun->stock_quantity);
        $this->assertSame(5, $urun->critical_quantity);
    }

    public function test_a_new_location_name_creates_the_tag(): void
    {
        $this->actingAs($this->yonetici())->post(route('admin.products.store'), $this->form(['new_location' => 'Depo']));

        $this->assertSame('Depo', Product::sole()->location->name);
    }

    public function test_an_empty_stock_turns_tracking_off(): void
    {
        $urun = $this->urun('Türk Kahvesi', null, 10, 2);

        $this->actingAs($this->yonetici())->put(route('admin.products.update', $urun), $this->form([
            'stock_quantity' => '', 'critical_quantity' => '',
        ]));

        $this->assertNull($urun->fresh()->stock_quantity);
        $this->assertNull($urun->fresh()->critical_quantity);
    }

    public function test_a_critical_level_needs_stock_tracking(): void
    {
        $this->actingAs($this->yonetici())->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->form(['critical_quantity' => 5]))
            ->assertSessionHasErrors('critical_quantity');
    }

    public function test_negative_numbers_are_refused(): void
    {
        $this->actingAs($this->yonetici())->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->form(['stock_quantity' => -1]))
            ->assertSessionHasErrors('stock_quantity');
    }

    // --- Stok sayfasi --------------------------------------------------------

    public function test_the_stock_page_lists_products_with_location_and_stock(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı']);
        $this->urun('Coca-Cola', $dolap, 18, 6);

        $this->actingAs($this->yonetici())->get(route('admin.stock.index'))
            ->assertOk()
            ->assertSee('Coca-Cola')
            ->assertSee('Buzdolabı')
            ->assertSee('18');
    }

    public function test_the_stock_page_filters_by_location(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı']);
        $raf = Location::create(['name' => 'Aburcubur Rafı']);
        $this->urun('Coca-Cola', $dolap, 18);
        $this->urun('Canga', $raf, 30);

        $this->actingAs($this->yonetici())->get(route('admin.stock.index', ['konum' => $raf->id]))
            ->assertOk()
            ->assertSee('Canga')
            ->assertDontSee('Coca-Cola');
    }

    public function test_the_stock_page_filters_critical_products(): void
    {
        $this->urun('Canga', null, 2, 5);
        $this->urun('Nero', null, 30, 5);
        $this->urun('Dido', null, 0, 5);

        $this->actingAs($this->yonetici())->get(route('admin.stock.index', ['durum' => 'critical']))
            ->assertOk()
            ->assertSee('Canga')
            ->assertSee('Dido')
            ->assertDontSee('Nero');
    }

    public function test_stock_is_entered_in_bulk(): void
    {
        $canga = $this->urun('Canga', null, 2, 5);
        $nero = $this->urun('Nero', null, 30, 5);

        $this->actingAs($this->yonetici())->post(route('admin.stock.update'), [
            'stok' => [
                $canga->id => ['quantity' => 40, 'critical' => 8],
                $nero->id => ['quantity' => '', 'critical' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(40, $canga->fresh()->stock_quantity);
        $this->assertSame(8, $canga->fresh()->critical_quantity);
        $this->assertNull($nero->fresh()->stock_quantity);
    }

    public function test_bulk_entry_leaves_unsent_products_alone(): void
    {
        $canga = $this->urun('Canga', null, 2, 5);
        $nero = $this->urun('Nero', null, 30, 5);

        $this->actingAs($this->yonetici())->post(route('admin.stock.update'), [
            'stok' => [$canga->id => ['quantity' => 40, 'critical' => 5]],
        ]);

        $this->assertSame(30, $nero->fresh()->stock_quantity);
    }

    public function test_bulk_entry_refuses_a_critical_level_without_stock(): void
    {
        $canga = $this->urun('Canga', null, 2, 5);

        $this->actingAs($this->yonetici())->from(route('admin.stock.index'))
            ->post(route('admin.stock.update'), ['stok' => [$canga->id => ['quantity' => '', 'critical' => 5]]])
            ->assertSessionHasErrors("stok.{$canga->id}.critical");

        $this->assertSame(2, $canga->fresh()->stock_quantity);
    }

    public function test_only_admins_reach_the_stock_page(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(route('admin.stock.index'))
            ->assertForbidden();
    }

    // --- Sayim ve menu -------------------------------------------------------

    public function test_the_count_page_offers_each_location_tag(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı']);
        $this->urun('Coca-Cola', $dolap, 18);
        Location::selfService();

        $this->actingAs($this->yonetici())->get(route('admin.stock.counts'))
            ->assertOk()
            ->assertSee(route('admin.stock.capture', $dolap))
            ->assertDontSee('Self Adisyon');
    }

    /** @param array<int,int> $sayilan urun id => sayilan */
    private function sayimOnayla(Location $konum, array $sayilan)
    {
        return $this->actingAs($this->yonetici())->post(route('admin.stock.confirm', $konum), [
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'record_type' => 'closing',
            'products' => collect($sayilan)->map(fn ($adet, $id) => ['product_id' => $id, 'verified_quantity' => $adet])->values()->all(),
        ]);
    }

    public function test_a_confirmed_count_becomes_the_stock(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı 2']);
        $kola = $this->urun('Coca-Cola', $dolap, 18);

        $this->sayimOnayla($dolap, [$kola->id => 15])->assertRedirect(route('admin.stock.counts'));

        $this->assertSame(15, $kola->fresh()->stock_quantity);
    }

    public function test_a_count_that_differs_logs_a_discrepancy(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı 2']);
        $kola = $this->urun('Coca-Cola', $dolap, 18);

        $this->sayimOnayla($dolap, [$kola->id => 15]);

        $fark = \App\Models\DiscrepancyLog::sole();
        $this->assertSame(18, $fark->expected_quantity);
        $this->assertSame(15, $fark->actual_quantity);
    }

    /** Takip kapali urunun ilk sayimi takibi baslatir; fark yoktur. */
    public function test_the_first_count_of_an_untracked_product_starts_tracking(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı 2']);
        $kola = $this->urun('Coca-Cola', $dolap, null);

        $this->sayimOnayla($dolap, [$kola->id => 12]);

        $this->assertSame(12, $kola->fresh()->stock_quantity);
        $this->assertSame(0, \App\Models\DiscrepancyLog::count());
    }

    /** Formdan baska konumun urunu gelse de o urunun stoku degismez. */
    public function test_a_count_does_not_touch_another_locations_product(): void
    {
        $dolap = Location::create(['name' => 'Buzdolabı 2']);
        $raf = Location::create(['name' => 'Raf 2']);
        $canga = $this->urun('Canga', $raf, 30);

        $this->sayimOnayla($dolap, [$canga->id => 0]);

        $this->assertSame(30, $canga->fresh()->stock_quantity);
    }

    public function test_the_locations_page_is_gone(): void
    {
        $this->assertFalse(Route::has('admin.locations.index'));
        $this->assertFalse(Route::has('admin.locations.create'));
    }

    public function test_the_menu_has_one_products_entry_for_stock_too(): void
    {
        $rotalar = collect(Navigation::groups($this->yonetici()))->pluck('items')->flatten(1)->pluck('route');

        $this->assertContains('admin.products.index', $rotalar);
        $this->assertNotContains('admin.stock.index', $rotalar);
        $this->assertNotContains('admin.locations.index', $rotalar);
    }

    public function test_the_product_pages_share_the_stock_tabs(): void
    {
        $this->actingAs($this->yonetici())->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee(route('admin.stock.index'))
            ->assertSee(route('admin.stock.counts'));
    }
}
