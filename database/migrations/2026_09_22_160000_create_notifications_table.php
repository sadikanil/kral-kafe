<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uretilmis bildirimler (Dalga 11).
 *
 * KAYIT ile TESLIM bilerek ayri. Bugun teslim kanali yalnizca panel; e-posta
 * sonra gelecek ve ayni kayitlarin uzerine binecek (sent_at dolacak). Kaydi
 * teslimle birlestirseydik, kanal degisince gecmis bildirimlerin ne oldugu
 * cevaplanamaz hale gelirdi.
 *
 * unique_key IDEMPOTANS icin: cron iki kez calisabilir (Vercel Hobby'de sapma
 * ±59 dk, yeniden deneme ve elle tetikleme de mumkun) ve ayni bildirim iki kez
 * olusmamali. Anahtar "tur:kullanici:ilgili" bicimide uretiliyor.
 *
 * student_id: bildirimin KIMIN hakkinda oldugu; user_id KIME gittigi. Veli
 * bildirimi icin ikisi farkli, ogrenciye giden deneme hatirlatmasinda ayni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('unique_key', 120)->unique();
            $table->string('title', 150);
            $table->string('body', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        PostgresSecurity::lockDown('notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
