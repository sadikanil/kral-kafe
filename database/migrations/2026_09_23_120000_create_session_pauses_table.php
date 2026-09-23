<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 23: calisma oturumundaki duraklamalar (duraklat, 15 dk mola, ogle
 * arasi). Duraklamada gecen sure calisma sayilmaz.
 *
 * kind duz metin + PHP enum (App\Enums\PauseKind): Postgres enum()
 * migration'i cokertir (README SS10.2).
 *
 * Oturum basina tek ACIK duraklama: kismi tekil indeks (study_sessions ile
 * ayni desen). Iki sekmeden ayni anda "Duraklat" basilirsa ikinci satir
 * dogmasin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_session_id')->constrained('study_sessions')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['study_session_id', 'started_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX session_pauses_tek_acik
             ON session_pauses (study_session_id) WHERE ended_at IS NULL'
        );

        PostgresSecurity::lockDown('session_pauses');
    }

    public function down(): void
    {
        Schema::dropIfExists('session_pauses');
    }
};
