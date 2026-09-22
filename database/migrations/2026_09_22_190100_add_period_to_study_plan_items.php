<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan maddesine donem ekler: haftalik ya da aylik (Dalga 14).
 *
 * Dalga 13'te plan yalnizca haftalikti. Koc aylik hedef de verebilsin diye
 * donem bir boyut oldu.
 *
 * SUTUN ADI week_start OLARAK KALIYOR. Yeniden adlandirmak daha dogru
 * okunurdu ama canli veritabani ile dagitilmis kod AYNI ANDA ayni semaya
 * bakiyor: migration'lar koddan ONCE uygulaniyor (README SS12), dolayisiyla
 * bir sutunu yeniden adlandirmak o an canlida duran eski kodu aninda
 * kirardi. Eklemeli degisiklik geriye uyumlu, yeniden adlandirma degil.
 * Anlami artik "donem baslangici"; PlanPeriod::startFor() doldurur.
 *
 * Varsayilan 'week': mevcut satirlarin hepsi haftalik ve ADD COLUMN ...
 * DEFAULT hem Postgres'te hem SQLite'ta onlari doldurur - ayri bir geri
 * doldurma adimi gerekmiyor.
 *
 * foreignId()->constrained() BILEREK kullanilmadi: SQLite'ta tabloyu
 * bastan yazar ve ham SQL ile kurulmus indeksleri dusurur (README SS10.5,
 * study_sessions'ta bir kez isirdi). Burada eklenen yalnizca bir string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->string('period', 10)->default('week')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->dropColumn('period');
        });
    }
};
