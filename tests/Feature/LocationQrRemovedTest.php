<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Dalga 26: QR ile adisyon TAMAMEN kalkti. Lokasyon QR'lari Dalga 8'de
 * kaldirilan /tuketim/{qr} adresine gidiyordu - basili QR okutulunca 404.
 * Lokasyon artik yalnizca yoneticinin "urun nerede" etiketi.
 */
class LocationQrRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_location_qr_pages_are_gone(): void
    {
        $this->assertFalse(Route::has('admin.locations.qr'));
        $this->assertFalse(Route::has('admin.locations.print-qr'));
    }

    public function test_the_location_list_offers_no_qr(): void
    {
        Location::create(['name' => 'Buzdolabı', 'type' => 'fridge']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.locations.index'))
            ->assertOk()
            ->assertDontSee('QR');
    }
}
