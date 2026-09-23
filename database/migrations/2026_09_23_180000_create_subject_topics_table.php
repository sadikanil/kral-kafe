<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 30b: mufredat konulari (YKS konu listesi). Koc plani ders + konu
 * secerek kurar (karar, 23 Eyl: serbest yazi degil liste).
 *
 * Liste ayri bir migration'la dolar (database/data/yks_konular.json).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['subject_id', 'name']);
        });

        PostgresSecurity::lockDown('subject_topics');
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_topics');
    }
};
