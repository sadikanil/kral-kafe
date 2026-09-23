<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 25: ozel ders (Tier 3). Karar (23 Eyl): haftalik sabit saat + tek
 * seferlik degisiklik.
 *
 * Saatler kafe yerel saatiyle duz metin ("17:00"): ders "carsamba 17:00"
 * olarak tanimlanir, bir ana degil; UTC'ye cevirmek yaz saati degisiminde
 * dersi bir saat kaydirirdi. Tarihler de yerel gun (LocalDay).
 *
 * Istisna: bir tarihteki dersi iptal eder ya da baska gun/saate tasir.
 * (slot, date) tekil - ayni ders icin iki celisen karar olmasin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_lesson_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO: 1 Pazartesi .. 7 Pazar
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('student_id');
        });

        Schema::create('private_lesson_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('private_lesson_slot_id')->constrained('private_lesson_slots')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('cancelled')->default(false);
            $table->date('new_date')->nullable();
            $table->string('new_starts_at', 5)->nullable();
            $table->string('new_ends_at', 5)->nullable();
            $table->timestamps();

            $table->unique(['private_lesson_slot_id', 'date']);
        });

        PostgresSecurity::lockDown('private_lesson_slots', 'private_lesson_exceptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('private_lesson_exceptions');
        Schema::dropIfExists('private_lesson_slots');
    }
};
