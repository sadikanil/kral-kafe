<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Lokasyona bagli mevcut veriler temizleniyor.
        // Not: bu, stok kayitlarini ve urun atamalarini siler!
        //
        // TRUNCATE yerine DELETE kullaniliyor: Postgres'te yabanci anahtarla
        // referans verilen bir tabloyu TRUNCATE etmek CASCADE ya da yetki
        // yukseltmesi gerektiriyor. Bagimlilik sirasina gore silmek her
        // surucude ayni sekilde calisir.
        DB::table('product_locations')->delete();
        DB::table('stock_records')->delete();
        DB::table('discrepancy_logs')->delete();
        DB::table('locations')->delete();

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
