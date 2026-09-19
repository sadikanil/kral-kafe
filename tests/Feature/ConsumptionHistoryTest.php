<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumptionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function consume(User $user, string $consumedAt): void
    {
        $product = Product::firstOrCreate(
            ['name' => 'Test Ürün'],
            ['unit_price' => 10, 'unit_type' => 'paket']
        );
        $location = Location::firstOrCreate(
            ['qr_code' => 'LOC-TEST'],
            ['name' => 'Test Raf', 'type' => 'shelf']
        );

        Consumption::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'unit_price' => 10,
            'consumed_at' => $consumedAt,
            'is_undone' => false,
        ]);
    }

    public function test_history_page_groups_consumptions_into_available_months(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'subscription_status' => 'active',
        ]);

        $this->consume($user, '2026-01-15 10:00:00');
        $this->consume($user, '2026-01-20 10:00:00');
        $this->consume($user, '2026-03-02 10:00:00');

        $response = $this->actingAs($user)->get('/kullanici/gecmis');

        $response->assertOk();

        $months = $response->viewData('availableMonths')
            ->map(fn ($row) => [(int) $row->year, (int) $row->month])
            ->all();

        $this->assertSame([[2026, 3], [2026, 1]], $months);
    }
}
