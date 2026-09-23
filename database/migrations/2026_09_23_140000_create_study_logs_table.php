<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 28: calisma kaydi ("Tarih · 200 soru").
 *
 * Ogrenci calisirken sayactan girer; oturumun dersi ayri bir secim olmaktan
 * cikti, son kayittan gelir. unit duz metin + PHP enum (App\Enums\StudyUnit):
 * Postgres enum() migration'i cokertir (README SS10.2).
 *
 * subject_id bos olabilir ("Genel"): deneme gibi tek derse ait olmayan is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('study_session_id')->constrained('study_sessions')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('unit', 10);
            $table->string('note', 120)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });

        PostgresSecurity::lockDown('study_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_logs');
    }
};
