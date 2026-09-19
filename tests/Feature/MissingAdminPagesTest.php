<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Controller'lari yazilmis ama Blade sablonu eksik oldugu icin 500 veren
 * uc sayfanin regresyon testleri.
 */
class MissingAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'subscription_status' => 'active']);
    }

    private function location(string $qr = 'LOC-TEST'): Location
    {
        return Location::create([
            'name' => 'Kahve İstasyonu', 'type' => 'shelf', 'qr_code' => $qr, 'is_active' => true,
        ]);
    }

    private function product(string $name = 'Filtre Kahve'): Product
    {
        return Product::create(['name' => $name, 'unit_price' => 25, 'unit_type' => 'paket']);
    }

    // --- admin.stock.review -------------------------------------------------

    public function test_stock_review_page_renders_after_photo_upload(): void
    {
        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => null]);
        Storage::fake('yukleme');
        Http::fake();

        $location = $this->location();
        $product = $this->product();
        $location->products()->attach($product->id, ['expected_quantity' => 10, 'min_quantity' => 2]);

        $this->actingAs($this->admin())->post("/yonetim/stok/{$location->id}/yukle", [
            'record_type' => 'opening',
            'photos' => [UploadedFile::fake()->image('raf.jpg')],
        ])->assertRedirect();

        $batch = StockPhoto::sole()->batch_id;

        $response = $this->actingAs($this->admin())
            ->get("/yonetim/stok/{$location->id}/analiz/{$batch}");

        $response->assertOk()->assertSee($location->name);
        Http::assertNothingSent();
    }

    public function test_stock_review_form_field_names_match_the_confirm_validation_rules(): void
    {
        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => null]);
        Storage::fake('yukleme');
        Http::fake();

        $location = $this->location();
        $product = $this->product();
        $location->products()->attach($product->id, ['expected_quantity' => 10, 'min_quantity' => 2]);

        $this->actingAs($this->admin())->post("/yonetim/stok/{$location->id}/yukle", [
            'record_type' => 'opening',
            'photos' => [UploadedFile::fake()->image('raf.jpg')],
        ]);

        $batch = StockPhoto::sole()->batch_id;
        $html = $this->actingAs($this->admin())
            ->get("/yonetim/stok/{$location->id}/analiz/{$batch}")
            ->assertOk()
            ->getContent();

        // confirm() su alanlari bekliyor; form bunlari gondermezse akis kopar.
        $this->assertStringContainsString('name="batch_id"', $html);
        $this->assertStringContainsString('name="record_type"', $html);
        $this->assertMatchesRegularExpression(
            '/name="products\[\d+\]\[product_id\]"/', $html,
            'Form products[..][product_id] alanini gondermiyor'
        );
        $this->assertMatchesRegularExpression(
            '/name="products\[\d+\]\[verified_quantity\]"/', $html,
            'Form products[..][verified_quantity] alanini gondermiyor'
        );
    }

    // --- admin.stock.discrepancy --------------------------------------------

    public function test_discrepancy_detail_page_renders(): void
    {
        $location = $this->location();
        $product = $this->product();

        $log = DiscrepancyLog::create([
            'location_id' => $location->id,
            'product_id' => $product->id,
            'expected_quantity' => 10,
            'actual_quantity' => 6,
            'record_type' => 'closing',
            'detected_at' => '2026-03-01 09:00:00',
            'resolved' => false,
        ]);

        $this->actingAs($this->admin())
            ->get("/yonetim/stok/tutarsizlik/{$log->id}")
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee($location->name);
    }

    public function test_discrepancy_page_offers_the_resolution_field_the_controller_requires(): void
    {
        $log = DiscrepancyLog::create([
            'location_id' => $this->location()->id,
            'product_id' => $this->product()->id,
            'expected_quantity' => 10,
            'actual_quantity' => 6,
            'record_type' => 'closing',
            'detected_at' => '2026-03-01 09:00:00',
            'resolved' => false,
        ]);

        $html = $this->actingAs($this->admin())
            ->get("/yonetim/stok/tutarsizlik/{$log->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('resolution_notes', $html);
    }

    public function test_a_resolved_discrepancy_still_renders(): void
    {
        $resolver = $this->admin();
        $log = DiscrepancyLog::create([
            'location_id' => $this->location()->id,
            'product_id' => $this->product()->id,
            'expected_quantity' => 10,
            'actual_quantity' => 10,
            'record_type' => 'opening',
            'detected_at' => '2026-03-01 09:00:00',
            'resolved' => true,
            'resolution_notes' => 'Sayim tekrarlandi.',
            'resolved_by' => $resolver->id,
            'resolved_at' => '2026-03-02 09:00:00',
        ]);

        $this->actingAs($resolver)
            ->get("/yonetim/stok/tutarsizlik/{$log->id}")
            ->assertOk();
    }

    // --- admin.reports.user -------------------------------------------------

    public function test_user_report_page_renders_with_consumptions(): void
    {
        $customer = User::factory()->create(['role' => 'student']);
        $location = $this->location();
        $product = $this->product('Latte');

        Consumption::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'unit_price' => 25,
            'consumed_at' => '2026-03-05 12:00:00',
            'is_undone' => false,
        ]);

        $this->actingAs($this->admin())
            ->get("/yonetim/raporlar/kullanici/{$customer->id}?year=2026&month=3")
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Latte');
    }

    public function test_user_report_page_renders_for_a_month_with_no_consumption(): void
    {
        $customer = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin())
            ->get("/yonetim/raporlar/kullanici/{$customer->id}?year=2026&month=8")
            ->assertOk()
            ->assertSee($customer->name);
    }
}
