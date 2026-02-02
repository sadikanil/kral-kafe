<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
        // Using raw SQL for better compatibility across DB versions without installing doctrine/dbal
        // Assuming MySQL
        DB::statement("ALTER TABLE products MODIFY COLUMN unit_type VARCHAR(50) NOT NULL DEFAULT 'paket'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('emoji');
        });

        // Reverting enum is tricky if there are values not in enum, 
        // effectively we can't safely revert this part without data loss potential.
        // We will just leave it as string in down or try to revert to a wider enum if needed.
        // For now, attempting to revert to original state:
        // DB::statement("ALTER TABLE products MODIFY COLUMN unit_type ENUM('piece', 'kg', 'liter') NOT NULL DEFAULT 'piece'");
    }
};
