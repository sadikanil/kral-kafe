<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haftalik calisma plani maddeleri (Dalga 13).
 *
 * Haftalik HEDEF (study_goals, Dalga 5) "ne kadar", bu tablo "NE" sorusunu
 * cevapliyor. Ikisi birlikte anlamli: 20 saat calisip hic matematik
 * yapmamak hedef cubuguna yansimiyor.
 *
 * week_start DATE ve madde ona bagli: GECMIS HAFTA YENIDEN YAZILMAZ. Plani
 * degistirmek gecmis haftanin "tuttu mu" cevabini degistirmemeli -
 * study_goals'un gecerlilik araligi kararinin aynisi.
 *
 * status string, enum DEGIL (bkz. README SS10.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('title', 150);
            $table->date('week_start');
            $table->string('status', 10)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'week_start']);
        });

        PostgresSecurity::lockDown('study_plan_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_items');
    }
};
