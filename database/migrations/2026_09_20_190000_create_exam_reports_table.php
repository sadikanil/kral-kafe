<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deneme sonuc raporlari: yonetici ogrencinin sayfasina PDF yukler, yapay
 * zeka PDF'i okuyup basarili/basarisiz alanlari cikarir.
 *
 * Dosya nesne depolamada (filesystems.uploads), burada yalnizca yolu.
 * Yol rastgele (uuid): bucket herkese acik olsa bile tahmin edilemez.
 *
 * analysis JSON: analizin sekli ExamReportAnalyzer'da tanimli; sutunlara
 * acilmadi cunku ders listesi PDF'ten PDF'e degisiyor (TYT/AYT/LGS, kurum
 * formati). Yapilandirilmis sonuc girisi (FEATURE 6) ayri tabloda gelecek.
 *
 * status: pending (yuklendi, analiz yok) | done | failed (error dolu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('exam_event_id')->nullable()->constrained('exam_events')->nullOnDelete();
            $table->string('title', 150);
            $table->string('file_path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('pending');
            $table->json('analysis')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });

        PostgresSecurity::lockDown('exam_reports');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_reports');
    }
};
