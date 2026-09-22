<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ders/alan tanimlari (Dalga 12).
 *
 * VERIDEN gelir, koda gomulmez: TYT, AYT ve LGS ders listeleri farkli ve
 * kurumdan kuruma degisebiliyor. Koda gomseydik yeni bir sinav turu eklemek
 * ya da bir dersi bolmek deploy gerektirirdi.
 *
 * Seeder standart TYT/AYT derslerini yukluyor; yonetici panelden ekleyip
 * cikarabiliyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('exam_type', 10);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['exam_type', 'name']);
            $table->index(['exam_type', 'sort_order']);
        });

        PostgresSecurity::lockDown('subjects');
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
