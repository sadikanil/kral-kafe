<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odak modu (Faz 4): odak modundayken uygulamadan kac kez ve toplam ne kadar
 * ayrildigi. Sure calismadan DUSULMEZ; ogrenci ve koc gorur, veli gormez.
 * Eklemeli: canlida duran eski kod bu sutunlari bilmeden calismaya devam eder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->unsignedInteger('focus_away_count')->default(0);
            $table->unsignedInteger('focus_away_seconds')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['focus_away_count', 'focus_away_seconds']);
        });
    }
};
