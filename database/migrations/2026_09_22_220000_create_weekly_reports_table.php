<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Haftalik veli raporu (Dalga 15a).
 *
 * payload JSON ve SAKLANIYOR, her acilista yeniden hesaplanmiyor. Rapor bir
 * ANLIK GORUNTU: velinin gordugu sayinin altindan kaymamasi gerekiyor -
 * "gecen hafta 14 saat yazmisti" diyen veliyle sistem ayrisamaz.
 *
 * Canli hesaplamak daha az kod olurdu ama gecikmis bir onay ya da duzeltilen
 * bir plan maddesi, veliye gonderilmis bir raporu sessizce degistirirdi.
 *
 * coach_comment AYRI SUTUN, payload'in icinde degil: insan emegi ve
 * yeniden hesaplamada korunmasi gerekiyor. payload'in icinde olsaydi her
 * regenerate onu silerdi.
 *
 * unique(student_id, week_start): bir haftanin tek raporu olur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->date('week_start');
            $table->json('payload');
            $table->text('coach_comment')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['student_id', 'week_start']);
        });

        PostgresSecurity::lockDown('weekly_reports');
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
