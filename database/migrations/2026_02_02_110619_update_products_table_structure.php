<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
