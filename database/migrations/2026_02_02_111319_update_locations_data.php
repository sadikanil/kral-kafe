<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key constraints to allow truncation
        Schema::disableForeignKeyConstraints();

        // Clear existing data related to locations
        // Note: This will delete stock records and product assignments!
        DB::table('product_locations')->truncate();
        DB::table('stock_records')->truncate();
        DB::table('discrepancy_logs')->truncate();
        DB::table('locations')->truncate();

        Schema::enableForeignKeyConstraints();

        $locations = [
            [
                'name' => 'Mutfak',
                'type' => 'shelf',
                'description' => 'Genel mutfak alanı',
            ],
            [
                'name' => 'Kahve İstasyonu',
                'type' => 'shelf',
                'description' => 'Kahve ve çay hazırlama alanı',
            ],
            [
                'name' => 'Aburcubur Rafı',
                'type' => 'shelf',
                'description' => 'Atıştırmalık ve kraker rafı',
            ],
            [
                'name' => 'Buzdolabı',
                'type' => 'fridge',
                'description' => 'Soğuk içecek dolabı',
            ],
        ];

        foreach ($locations as $loc) {
            DB::table('locations')->insert([
                'name' => $loc['name'],
                'type' => $loc['type'],
                'description' => $loc['description'],
                'qr_code' => 'LOC-' . Str::upper(Str::random(8)),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No turning back from truncation
    }
};
