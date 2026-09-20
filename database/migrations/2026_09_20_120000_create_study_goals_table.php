<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calisma hedefleri.
 *
 * GECERLILIK ARALIGI olmasinin sebebi tek satirlik bir hedef sutununun
 * yapamayacagi sey: koc hedefi yukseltince GECMIS haftalarin "tuttu mu"
 * cevabi degismemeli. users.haftalik_hedef gibi tek bir alanda hedefi
 * degistiren kisi, farkinda olmadan gecmisi yeniden yazar.
 *
 * period sutunu var cunku onsuz satir belirsiz: 1200 dakika haftalik mi aylik
 * mi? Su an yalnizca 'weekly' uretiliyor.
 *
 * created_by nullable: hedefi bir koc/yonetici koyar ama o kullanici sonradan
 * silinirse hedef kaybolmamali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 10)->default('weekly');
            $table->unsignedInteger('target_minutes');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'effective_from']);
        });

        PostgresSecurity::lockDown('study_goals');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_goals');
    }
};
