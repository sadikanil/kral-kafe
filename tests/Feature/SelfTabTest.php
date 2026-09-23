<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Self adisyon: ogrenci QR okutmadan panelden urun ekler.
 */
class SelfTabTest extends TestCase
{
    use RefreshDatabase;

    private function urun(string $ad = 'Filtre Kahve', float $fiyat = 25, bool $aktif = true): Product
    {
        return Product::create(['name' => $ad, 'unit_price' => $fiyat, 'unit_type' => 'adet', 'is_active' => $aktif]);
    }

    public function test_the_self_service_location_is_created_once_and_stays_closed(): void
    {
        $a = Location::selfService();
        $b = Location::selfService();

        $this->assertSame($a->id, $b->id);
        $this->assertFalse($a->is_active, 'Sanal lokasyon stok/QR ekranlarina girmemeli');
        $this->assertTrue($a->isSelfService());
        // Migration dort fiziksel lokasyon tohumluyor; sanal olan tek satir.
        $this->assertSame(1, Location::where('qr_code', Location::SELF_SERVICE_QR)->count());
    }

    public function test_a_student_adds_a_product_to_their_own_tab(): void
    {
        $ogrenci = User::factory()->student()->create();
        $urun = $this->urun();
        $this->urun('Pasif Ürün', 10, false);

        $this->actingAs($ogrenci)->get('/kullanici/adisyon')
            ->assertOk()
            ->assertSee('Filtre Kahve')
            ->assertDontSee('Pasif Ürün');

        $this->actingAs($ogrenci)
            ->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 2])
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('success');

        $kayit = Consumption::sole();
        $this->assertSame($ogrenci->id, $kayit->user_id);
        $this->assertSame(Location::selfService()->id, $kayit->location_id);
        $this->assertSame(2, $kayit->quantity);
        $this->assertSame('50.00', (string) $kayit->total_price);

        // Aylik toplam ve Odemeler dokumu degisiklik olmadan bunu da sayar
        // (UX turu, 23 Eyl: tuketim listesi panelden Odemeler'e tasindi).
        $this->assertSame(50.0, (float) $ogrenci->getCurrentMonthTotal());
        $this->actingAs($ogrenci)->get('/kullanici/adisyon')->assertOk()->assertSee('Bugün eklediklerim')->assertSee('Geri al');
        $this->actingAs($ogrenci)->get(route('user.payments'))->assertOk()->assertSee('Filtre Kahve ×2');
    }

    public function test_an_inactive_product_or_bad_quantity_is_refused(): void
    {
        $ogrenci = User::factory()->student()->create();
        $pasif = $this->urun('Pasif', 10, false);
        $aktif = $this->urun();

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $pasif->id, 'quantity' => 1])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($ogrenci)->from('/kullanici/adisyon')
            ->post('/kullanici/adisyon', ['product_id' => $aktif->id, 'quantity' => 11])
            ->assertRedirect('/kullanici/adisyon')->assertSessionHasErrors('quantity');

        $this->assertSame(0, Consumption::count());
    }

    public function test_undo_is_limited_to_the_owner_and_sixty_seconds(): void
    {
        $ogrenci = User::factory()->student()->create();
        $baskasi = User::factory()->student()->create();
        $urun = $this->urun();

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1]);
        $kayit = Consumption::sole();

        $this->actingAs($baskasi)->post(route('user.tab.undo', $kayit))->assertForbidden();

        $this->actingAs($ogrenci)->post(route('user.tab.undo', $kayit))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($kayit->fresh()->is_undone);

        // Suresi dolmus kayit
        $eski = Consumption::create([
            'user_id' => $ogrenci->id, 'location_id' => Location::selfService()->id, 'product_id' => $urun->id,
            'quantity' => 1, 'unit_price' => 25, 'consumed_at' => now()->subMinutes(5),
        ]);
        $this->actingAs($ogrenci)->post(route('user.tab.undo', $eski))->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($eski->fresh()->is_undone);
    }

    public function test_a_student_without_an_active_subscription_cannot_add(): void
    {
        $ogrenci = User::factory()->student()->create(['subscription_status' => 'suspended']);
        $urun = $this->urun();

        $this->actingAs($ogrenci)->post('/kullanici/adisyon', ['product_id' => $urun->id, 'quantity' => 1])
            ->assertRedirect(route('user.dashboard'));

        $this->assertSame(0, Consumption::count());
    }
}
