<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uyelik paketleri (katalog) ve pakete dahil urunler.
 *
 * packages.monthly_price KATALOG fiyatidir; abonelik acilirken
 * subscriptions.price'a kopyalanir ve fatura oradan okur. Katalog fiyati
 * degisince gecmis abonelikler ve faturalar degismez.
 *
 * usage_window (kullanim saat araligi) ertelendi: bu dalgada hicbir sey
 * okumuyor. Kapsam kurallari bilerek dar: "su urunden donemde su kadar
 * ucretsiz" (package_items). Tuketimin kapsama dusmesi Dalga 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->decimal('monthly_price', 10, 2);
            $table->boolean('has_reserved_table')->default(false);
            $table->boolean('includes_coaching')->default(false);
            $table->unsignedSmallInteger('weekly_mock_exams')->default(0);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('included_quantity')->nullable();
            $table->string('period', 10)->default('monthly');
            $table->timestamps();

            $table->unique(['package_id', 'product_id']);
        });

        PostgresSecurity::lockDown('packages', 'package_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};
