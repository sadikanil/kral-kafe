<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koc notlari ve koc-veli gorusme kaydi (Dalga 14b).
 *
 * kind ve visibility DUZ STRING, enum() degil: Postgres'te enum()->change()
 * migration'i cokertiyor ve SQLite'ta komsu sutunun kisitini dusuruyor
 * (SS10.2). Dogrulama App\Enums ve Rule::enum() ile.
 *
 * visibility varsayilani 'parent' (karar 6): veli profile islenen her seyi
 * gorur. Varsayilanin kapali olmasi bu kuralin tersine calisirdi.
 *
 * occurred_on NULLABLE: yalnizca gorusme kaydi "ne zaman oldu" ister.
 * Gorusme cogu zaman sonradan yaziliyor, created_at'i gorusme ani saymak
 * tarihi gunler kaydirirdi. Zorunluluk turu BILEN tarafta - yazma ucunda.
 *
 * created_by, projedeki diger tablolarla ayni (study_plan_items,
 * coach_assignments, student_parent). SS8.3 taslaginda coach_id yaziyordu;
 * yoneticinin de yazabilmesi (karar 11) adi yaniltici kilardi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10)->default('note');
            $table->string('visibility', 10)->default('parent');
            $table->text('body');
            $table->date('occurred_on')->nullable();
            $table->timestamps();

            // Ogrencinin notlari her ekranda tarihe gore listeleniyor.
            $table->index(['student_id', 'created_at']);
        });

        PostgresSecurity::lockDown('coach_notes');
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_notes');
    }
};
