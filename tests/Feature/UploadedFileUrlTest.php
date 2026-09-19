<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Yuklenen dosyalarin adresi, sabit "/storage/..." yolu yerine yapilandirilmis
 * diskten uretilmeli; aksi halde nesne depolamaya (Supabase Storage) gecince
 * tum gorseller kirilir.
 */
class UploadedFileUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_image_src_comes_from_the_configured_upload_disk(): void
    {
        config(['filesystems.uploads' => 'public']);

        $product = Product::create([
            'name' => 'Latte', 'unit_price' => 30, 'unit_type' => 'paket',
            'image_url' => 'products/latte.jpg',
        ]);

        $this->assertSame(
            Storage::disk('public')->url('products/latte.jpg'),
            $product->image_src
        );
    }

    public function test_product_image_src_follows_a_swapped_disk(): void
    {
        config(['filesystems.uploads' => 'baska-disk']);
        Storage::fake('baska-disk');

        $product = Product::create([
            'name' => 'Espresso', 'unit_price' => 20, 'unit_type' => 'paket',
            'image_url' => 'products/espresso.jpg',
        ]);

        $this->assertSame(
            Storage::disk('baska-disk')->url('products/espresso.jpg'),
            $product->image_src
        );
    }

    public function test_a_product_without_an_image_has_no_src(): void
    {
        $product = Product::create(['name' => 'Su', 'unit_price' => 5, 'unit_type' => 'paket']);

        $this->assertNull($product->image_src);
    }


    public function test_no_view_builds_an_upload_url_by_hand(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/**/*.blade.php'), GLOB_BRACE) ?: [] as $file) {
            $contents = file_get_contents($file);
            if (preg_match("/asset\(\s*'storage\//", $contents)) {
                $offenders[] = str_replace(resource_path('views/'), '', $file);
            }
        }
        foreach (glob(resource_path('views/*/*/*.blade.php')) ?: [] as $file) {
            $contents = file_get_contents($file);
            if (preg_match("/asset\(\s*'storage\//", $contents)) {
                $offenders[] = str_replace(resource_path('views/'), '', $file);
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Bu sablonlar yuklenen dosya adresini elle kuruyor; nesne depolamada kirilir"
        );
    }
}
