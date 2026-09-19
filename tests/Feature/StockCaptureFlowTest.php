<?php

namespace Tests\Feature;

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
 * Stok sayim akisi ucdan uca: sayim formu -> fotograf yukleme -> analiz sayfasi.
 *
 * Formu atlayip dogrudan POST etmek bu akisi dogrulamaz; formun GERCEKTEN
 * gonderdigi alan adlari controller'in bekledikleriyle uyusmali.
 */
class StockCaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'subscription_status' => 'active']);
    }

    private function stockedLocation(): Location
    {
        $location = Location::create([
            'name' => 'Buzdolabı', 'type' => 'fridge', 'qr_code' => 'LOC-F', 'is_active' => true,
        ]);
        $product = Product::create(['name' => 'Ayran', 'unit_price' => 15, 'unit_type' => 'paket']);
        $location->products()->attach($product->id, ['expected_quantity' => 12, 'min_quantity' => 3]);

        return $location;
    }

    public function test_capture_form_sends_the_field_name_upload_expects(): void
    {
        $location = $this->stockedLocation();

        $html = $this->actingAs($this->admin())
            ->get("/yonetim/stok/{$location->id}/kayit")
            ->assertOk()
            ->getContent();

        // uploadPhotos() 'record_type' bekliyor; form baska bir ad gonderirse akis kopar.
        $this->assertStringContainsString('name="record_type"', $html,
            'Sayim formu record_type alanini gondermiyor');
        $this->assertMatchesRegularExpression('/value="(opening|closing)"/', $html,
            'Sayim formu opening/closing disinda bir deger sunuyor');
    }

    public function test_the_whole_capture_flow_works_with_the_values_the_form_offers(): void
    {
        config(['filesystems.uploads' => 'yukleme', 'services.openai.api_key' => null]);
        Storage::fake('yukleme');
        Http::fake();

        $location = $this->stockedLocation();
        $admin = $this->admin();

        // Formun sundugu HER secenek kabul edilmeli.
        foreach (['opening', 'closing'] as $recordType) {
            $this->actingAs($admin)
                ->post("/yonetim/stok/{$location->id}/yukle", [
                    'record_type' => $recordType,
                    'photos' => [UploadedFile::fake()->image('raf.jpg')],
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $batch = StockPhoto::latest('id')->first()->batch_id;

        $this->actingAs($admin)
            ->get("/yonetim/stok/{$location->id}/analiz/{$batch}")
            ->assertOk();
    }

    public function test_stock_photo_url_comes_from_the_configured_upload_disk(): void
    {
        // Yerel "public" diski ile asset('storage/..') ayni adresi uretir, bu yuzden
        // ayirt edici olmasi icin baska bir disk secilir.
        config(['filesystems.uploads' => 'baska-disk']);
        Storage::fake('baska-disk');

        $photo = StockPhoto::create([
            'location_id' => $this->stockedLocation()->id,
            'batch_id' => 'b1',
            'record_type' => 'opening',
            'photo_path' => 'stock_photos/1/a.jpg',
            'admin_id' => $this->admin()->id,
        ]);

        $this->assertSame(
            Storage::disk('baska-disk')->url('stock_photos/1/a.jpg'),
            $photo->photo_url
        );
    }

    public function test_no_model_builds_an_upload_url_by_hand(): void
    {
        $offenders = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            if (preg_match("/asset\(\s*'storage\//", file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'Bu modeller yuklenen dosya adresini elle kuruyor; nesne depolamada kirilir');
    }
}
