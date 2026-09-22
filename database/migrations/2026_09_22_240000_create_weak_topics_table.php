<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zayif konu listesi (Dalga 17b).
 *
 * status duz string, enum() DEGIL (SS10.2). Dogrulama uygulama tarafinda.
 *
 * subject_id NULLABLE: her zayif konu bir derse oturmuyor ("soru cozme
 * hizi", "deneme stresi").
 *
 * source su an yalnizca 'coach' yaziliyor. Sutun duruyor cunku SS7-H
 * konularin deneme sonucundan ve PDF analizinden de beslenmesini ongoruyor;
 * o kaynak geldiginde yeni bir migration gerekmesin. Otomatik turetme
 * BILEREK ertelendi: "dusuk net" esigini sistemin kendi basina belirlemesi
 * SS6.1-6'ya (sistem sayi gosterir, sifat uretmez) yaklasir ve ayri bir
 * karar gerektirir.
 *
 * closed_at ile status birlikte tutuluyor: "kapandi mi" ile "ne zaman
 * kapandi" ayri sorular ve ikincisi ogrenciye gosterilen tek ilerleme
 * isareti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weak_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->string('topic', 150);
            $table->string('source', 10)->default('coach');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        PostgresSecurity::lockDown('weak_topics');
    }

    public function down(): void
    {
        Schema::dropIfExists('weak_topics');
    }
};
