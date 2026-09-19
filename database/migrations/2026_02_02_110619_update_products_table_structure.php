<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add emoji column
        Schema::table('products', function (Blueprint $table) {
            $table->string('emoji', 20)->nullable()->after('name');
        });

        // Change unit_type from enum to string to allow custom types
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit_type', 50)->default('paket')->change();
        });

        // Postgres'te enum(), varchar + CHECK kisiti olarak olusuyor ve change()
        // bu kisiti oldugu yerde birakiyor. Arayuz serbest metin birim adlari
        // ('paket', 'teneke kutu', 'pet sise') sundugu icin kisit kalirsa her urun
        // ekleme/guncelleme SQLSTATE[23514] ile duser. SQLite ve MySQL'de change()
        // sutunu bastan yazdigi icin boyle bir kalinti olusmuyor.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_unit_type_check');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('emoji');
        });

        // unit_type is intentionally left as a string: reverting to the original
        // enum('piece','kg','liter') would drop any custom unit types already stored.
    }
};
