<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 26: urunun NEREDE durdugu ve stogu yalnizca yoneticinin girdisi.
 * Urun formunda her aktif lokasyon icin "burada" + adet.
 */
class ProductPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function urun(): Product
    {
        return Product::create(['name' => 'Tost', 'unit_price' => 45, 'unit_type' => 'adet', 'is_active' => true]);
    }

    private function guncelle(Product $urun, array $yerler)
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.products.update', $urun), [
                'name' => $urun->name, 'unit_price' => 45, 'unit_type' => 'adet', 'is_active' => 1,
                'places_present' => 1, 'places' => $yerler,
            ]);
    }

    public function test_the_admin_places_a_product_with_its_stock(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Dolap', 'type' => 'cabinet']);

        $this->guncelle($urun, [$dolap->id => ['on' => 1, 'quantity' => 12]])->assertSessionHasNoErrors();

        $this->assertSame(12, ProductLocation::where(['product_id' => $urun->id, 'location_id' => $dolap->id])->sole()->expected_quantity);
    }

    public function test_the_stock_of_an_existing_place_is_updated(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Dolap', 'type' => 'cabinet']);
        ProductLocation::create(['product_id' => $urun->id, 'location_id' => $dolap->id, 'expected_quantity' => 3, 'min_quantity' => 0]);

        $this->guncelle($urun, [$dolap->id => ['on' => 1, 'quantity' => 20]]);

        $this->assertSame(20, ProductLocation::sole()->expected_quantity);
    }

    public function test_unticking_removes_the_place(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Dolap', 'type' => 'cabinet']);
        ProductLocation::create(['product_id' => $urun->id, 'location_id' => $dolap->id, 'expected_quantity' => 3, 'min_quantity' => 0]);

        $this->guncelle($urun, [$dolap->id => ['quantity' => 3]]);

        $this->assertSame(0, ProductLocation::count());
    }

    public function test_a_negative_stock_is_refused(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Dolap', 'type' => 'cabinet']);

        $this->guncelle($urun, [$dolap->id => ['on' => 1, 'quantity' => -1]])->assertSessionHasErrors('places.*.quantity');
    }

    /** Formu gondermeyen (eski) istek yerlesime dokunmaz. */
    public function test_placements_are_untouched_without_the_section(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Dolap', 'type' => 'cabinet']);
        ProductLocation::create(['product_id' => $urun->id, 'location_id' => $dolap->id, 'expected_quantity' => 3, 'min_quantity' => 0]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.products.update', $urun), ['name' => 'Tost', 'unit_price' => 45, 'unit_type' => 'adet']);

        $this->assertSame(1, ProductLocation::count());
    }

    public function test_the_edit_page_lists_places_with_stock(): void
    {
        $urun = $this->urun();
        $dolap = Location::create(['name' => 'Kiler Dolabı', 'type' => 'cabinet']);
        ProductLocation::create(['product_id' => $urun->id, 'location_id' => $dolap->id, 'expected_quantity' => 7, 'min_quantity' => 0]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.products.edit', $urun))
            ->assertSee('Kiler Dolabı')
            ->assertSee('value="7"', false);
    }
}
