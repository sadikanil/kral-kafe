<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\StockPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_photos_are_written_to_the_configured_upload_disk(): void
    {
        // Uretimde bu disk Supabase Storage'a (s3) isaret ediyor.
        config(['filesystems.uploads' => 'yukleme-diski']);
        Storage::fake('yukleme-diski');

        $admin = User::factory()->create(['role' => 'admin', 'subscription_status' => 'active']);
        $location = Location::create([
            'name' => 'Buzdolabı', 'type' => 'fridge', 'qr_code' => 'LOC-X', 'is_active' => true,
        ]);

        $this->actingAs($admin)->post("/yonetim/stok/{$location->id}/yukle", [
            'record_type' => 'opening',
            'photos' => [UploadedFile::fake()->image('raf.jpg')],
        ])->assertRedirect();

        $photo = StockPhoto::sole();

        Storage::disk('yukleme-diski')->assertExists($photo->photo_path);
        $this->assertStringContainsString("stock_photos/{$location->id}", $photo->photo_path);
    }
}
