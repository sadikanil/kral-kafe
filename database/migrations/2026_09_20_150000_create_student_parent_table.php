<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Veli-ogrenci bagi (coka cok).
 *
 * Iki taraf da users tablosu: bir veli birden fazla cocugu, bir ogrencinin
 * birden fazla velisi (anne + baba) olabilir. users.parent_id gibi tek bir
 * sutun ikinci veliyi temsil edemezdi.
 *
 * Veli sinirinin TEK kaynagi bu tablo: veli paneli yalnizca burada bagli
 * ogrencileri gorur (bkz. User::accessibleStudentIds). Bag global scope ile
 * DEGIL, policy + acik scope ile uygulanir - global scope yonetici
 * toplamlarina gorunmez sekilde sizar.
 *
 * created_by nullable: bagi kuran yonetici sonradan silinirse bag kalmali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_parent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'parent_id']);
            $table->index('parent_id');
        });

        PostgresSecurity::lockDown('student_parent');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_parent');
    }
};
