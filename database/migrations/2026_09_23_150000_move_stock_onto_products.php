<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 29: stok urunun uzerinde, konum bir etiket (karar, 23 Eyl).
 *
 * Eskiden bir urun birden fazla rafta durup her rafin ayri stoku vardi
 * (product_locations). Artik tek konum ve tek sayi:
 *
 *   location_id       - konum etiketi (locations tablosu etiket listesi olarak kaldi;
 *                       sayim, fotograf ve tutarsizlik kayitlari ona bagli)
 *   stock_quantity    - null = stok takibi KAPALI (sicak icecek, cay)
 *   critical_quantity - bu sayiya inince yoneticiye bildirim; null = uyari yok
 *   description       - formda vardi ama hic kaydedilmiyordu
 *
 * Veri tasimasi tasinabilir SQL: --pretend onu da gosterir (PHP dongusu
 * gostermezdi, README SS10.4 tuzagi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->integer('stock_quantity')->nullable();
            $table->integer('critical_quantity')->nullable();
        });

        // Konum: en cok stoku olan raf. Stok: raflarin toplami. Kritik:
        // raflardaki en yuksek esik (0 esik "uyari yok" demekti).
        DB::statement(<<<'SQL'
            UPDATE products SET
                location_id = (SELECT pl.location_id FROM product_locations pl
                               WHERE pl.product_id = products.id
                               ORDER BY pl.expected_quantity DESC, pl.location_id LIMIT 1),
                stock_quantity = (SELECT SUM(pl.expected_quantity) FROM product_locations pl
                                  WHERE pl.product_id = products.id),
                critical_quantity = (SELECT NULLIF(MAX(pl.min_quantity), 0) FROM product_locations pl
                                     WHERE pl.product_id = products.id)
            WHERE EXISTS (SELECT 1 FROM product_locations pl WHERE pl.product_id = products.id)
            SQL);

        Schema::drop('product_locations');
    }

    public function down(): void
    {
        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->integer('expected_quantity')->default(0);
            $table->integer('min_quantity')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
        });
        \App\Support\PostgresSecurity::lockDown('product_locations');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['description', 'stock_quantity', 'critical_quantity']);
        });
    }
};
