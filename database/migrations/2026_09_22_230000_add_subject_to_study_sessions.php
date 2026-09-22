<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oturuma ders etiketi (Dalga 17a).
 *
 * ZORUNLU DEGIL ve olmayacak: etiketsiz oturum "Genel" sayilir. Zorunlu
 * kilmak masaya oturmanin onune bir soru koyardi ve ogrenci okutmayi
 * birakirdi.
 *
 * foreignId()->constrained() BILEREK KULLANILMADI.
 *
 * SQLite'ta study_sessions'a kisit eklemek tabloyu BASTAN YAZAR ve ham SQL
 * ile kurulmus "study_sessions_tek_acik_oturum" indeksini sessizce dusurur -
 * WHERE ended_at IS NULL yuklemi kaybolur ve "bir ogrencinin tek ACIK
 * oturumu" kurali "tek oturumu"na donusur. Bu tam olarak bir kez oldu
 * (README SS10.5) ve Dalga 10b'de de ayni sebeple kacinildi.
 *
 * Butunluk uygulama tarafinda: yazma ucu Rule::exists ile dogruluyor,
 * SessionSubjectTest indeksin ayakta kaldigini ayrica denetliyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->after('study_table_id');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn('subject_id');
        });
    }
};
