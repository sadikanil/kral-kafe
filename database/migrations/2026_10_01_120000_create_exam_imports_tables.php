<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kurum geneli deneme sonuc PDF'i (1 Ekim 2026).
 *
 * Sonuc PDF'i kuruma TOPLU geliyor: okul/sinif net listeleri + ogrenci
 * basina karne (konu bazli dogru/yanlis/bos, sube/kurum/ilce/il/genel
 * sira). Yonetici tek PDF yukler; yapay zeka sayfalari okur, satirlar
 * ogrencilere eslenir, yonetici kontrol edip yayinlar.
 *
 *   exam_imports      - yuklenen PDF ve islem durumu
 *   exam_import_rows  - PDF'teki her ogrenci satiri + eslesme + okunan veri
 *   exam_results      - yayinlanan sonuca puan, konu tablosu ve kaynak
 *
 * PDF TUM kurumun adlarini ve puanlarini tasir: ogrenciye ve veliye ASLA
 * acilmaz (README SS6.1-4). Ogrenci yalnizca kendi satirini gorur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_event_id')->constrained('exam_events')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('status', 20)->default('uploaded');
            $table->string('provider', 20)->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_import_id')->constrained('exam_imports')->cascadeOnDelete();
            $table->string('name');
            $table->string('class_label', 40)->nullable();
            $table->unsignedSmallInteger('card_page')->nullable();
            $table->boolean('card_read')->default(false);
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            // auto: ad birebir; suggested: kismi (kontrol et); manual: yonetici
            // secti; none: eslesmedi; skip: kafede olmayan katilimci.
            $table->string('match', 20)->default('none');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index('exam_import_id');
        });

        Schema::table('exam_results', function (Blueprint $table) {
            $table->decimal('score', 8, 3)->nullable();
            $table->json('topics')->nullable();
            $table->foreignId('exam_import_id')->nullable()->constrained('exam_imports')->nullOnDelete();
        });

        PostgresSecurity::lockDown('exam_imports', 'exam_import_rows');
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_import_id');
            $table->dropColumn(['score', 'topics']);
        });

        Schema::dropIfExists('exam_import_rows');
        Schema::dropIfExists('exam_imports');
    }
};
