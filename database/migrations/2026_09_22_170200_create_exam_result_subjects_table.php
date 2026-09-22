<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deneme sonucunun ders bazli kirilimi (Dalga 12).
 *
 * NET SUTUNU YOK: net = dogru - yanlis/4 ve HESAPLANIR (ExamResultSubject).
 * Sutunda tutmak ikinci bir dogruluk kaynagi yaratirdi - dogru/yanlis
 * duzeltilip net guncellenmezse ikisi sessizce ayrisir ve hangisinin dogru
 * oldugu cevapsiz kalir. Ayni gerekce Dalga 2'de study_tables.status icin
 * de verilmisti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_result_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_result_id')->constrained('exam_results')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->unsignedSmallInteger('correct')->default(0);
            $table->unsignedSmallInteger('wrong')->default(0);
            $table->unsignedSmallInteger('blank')->default(0);
            $table->timestamps();

            $table->unique(['exam_result_id', 'subject_id']);
        });

        PostgresSecurity::lockDown('exam_result_subjects');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_result_subjects');
    }
};
