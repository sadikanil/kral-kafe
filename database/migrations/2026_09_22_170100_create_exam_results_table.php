<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ogrenci basina deneme sonucu (Dalga 12).
 *
 * Takvimdeki denemeye (exam_events, Dalga 6b) bagli; ayri bir mock_exams
 * tablosu ACILMADI - exam_events onun yerine gecti (karar 13).
 *
 * SIRALAMA SUTUNLARININ HEPSI NULLABLE: kurum siralamasi denemenin ertesi
 * gunu, Turkiye geneli bir hafta sonra aciklanabiliyor. Sonuc girisi eksik
 * veriyle baslayip tamamlanabilmeli.
 *
 * Her siralamanin yaninda KATILIMCI SAYISI var: "1.240 kisde 87." anlamli,
 * ciplak "87." degil - siranin anlami denemeden denemeye degisir. Yuzdelik
 * dilimi sistem hesaplamiyor (karar 7).
 *
 * note: yoneticinin PDF ozetini okuyup yazdigi degerlendirme. Profile
 * islenen her sey veliye acik (karar 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_event_id')->constrained('exam_events')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('rank_institution')->nullable();
            $table->unsignedInteger('total_institution')->nullable();
            $table->unsignedInteger('rank_district')->nullable();
            $table->unsignedInteger('total_district')->nullable();
            $table->unsignedInteger('rank_city')->nullable();
            $table->unsignedInteger('total_city')->nullable();
            $table->unsignedInteger('rank_country')->nullable();
            $table->unsignedInteger('total_country')->nullable();

            $table->string('note', 1000)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Bir ogrencinin bir denemede tek sonucu olur.
            $table->unique(['exam_event_id', 'student_id']);
        });

        PostgresSecurity::lockDown('exam_results');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
