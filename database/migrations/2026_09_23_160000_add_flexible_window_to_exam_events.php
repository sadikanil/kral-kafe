<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 30a: serbest deneme - tarihi ogrenci secer (pencere icinde).
 *
 * exam_date pencerenin ilk gunu olarak DOLU kalir: sonuc siralamasi, net
 * grafigi ve raporlar ona dayaniyor. available_until pencerenin son gunu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_events', function (Blueprint $table) {
            $table->boolean('is_flexible')->default(false);
            $table->date('available_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_events', function (Blueprint $table) {
            $table->dropColumn(['is_flexible', 'available_until']);
        });
    }
};
