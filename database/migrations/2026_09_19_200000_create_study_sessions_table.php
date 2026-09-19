<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calisma oturumlari - sistemin kalbi.
 *
 * Masa yabanci anahtari restrictOnDelete: gecmis kayit, masayi silmek icin
 * feda edilmemeli. Kullanimdan kalkan masa is_active=false yapilir.
 *
 * KISMI TEKIL INDEKS: bir ogrencinin ayni anda yalnizca bir ACIK oturumu
 * olabilir. Uygulama katmani tek basina yetmez - iki es zamanli istek ikisi de
 * "acik oturum yok" gorup ikisi de insert eder. Kisit veritabaninda.
 *
 * Kapali satirlar kisiti tetiklememeli, yoksa ogrenci gunde yalnizca bir kez
 * calisabilirdi; bu yuzden indeks KISMI (WHERE ended_at IS NULL). Hem SQLite
 * hem Postgres bu sozdizimini destekliyor.
 *
 * Kaynak belgedeki 'source' (qr|manual|admin) ve 'note' sutunlari ertelendi:
 * bu dalgada hicbir sey yazmiyor ve okumuyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('study_table_id')->constrained('study_tables')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'started_at']);
            $table->index('ended_at');
        });

        DB::statement(
            'CREATE UNIQUE INDEX study_sessions_tek_acik_oturum
             ON study_sessions (student_id) WHERE ended_at IS NULL'
        );

        PostgresSecurity::lockDown('study_sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
