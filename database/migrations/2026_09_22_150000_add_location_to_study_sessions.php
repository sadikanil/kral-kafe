<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oturum baslangicindaki konum (Dalga 10b).
 *
 * Sert kapi DEGIL: ic mekanda GPS sapmasi 50-100 metreyi buluyor, izin
 * reddedilebilir. Konum yalnizca onay kuyrugunda bir isaret; asil dogrulama
 * yoneticinin onayi (Dalga 9).
 *
 * Yalnizca BASLANGIC kaydediliyor; oturum boyunca takip yok (SS6.1-7).
 *
 * Sutunlar NULLABLE ve varsayilansiz: "konum yok" ile "kafede degil" ayri
 * seyler. Sifir/uydurma bir koordinat yazmak, izni kapali her ogrenciyi
 * Gine Korfezi'nde gosterirdi.
 *
 * decimal(10,7): ~1 cm cozunurluk, GPS'in verebileceginden fazlasi. float
 * kullanmak karsilastirmalari surucuye gore degistirirdi.
 *
 * FOREIGN KEY YOK, o yuzden SQLite tabloyu yeniden yazmiyor ve Dalga 3'un
 * kismi tekil indeksi yerinde kaliyor (bkz. SS10.5 - Dalga 9'da isirmisti).
 * StudySessionTest'teki "the unique index is partial" testi bunu dogruluyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('rejection_reason');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('accuracy', 8, 2)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy']);
        });
    }
};
