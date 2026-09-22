<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuketime paket kapsami (Dalga 8).
 *
 * Dalga 7'de package_items YALNIZCA TANIMDI: paketin neyi kapsadigi
 * yaziliydi ama tuketim onu hic okumuyordu. Bu sutun tanimi kayda baglar.
 *
 * SAYI, BAYRAK DEGIL. Yol haritasi bunu `covered_by_package` (bool) diye
 * planlamisti; sayiya cevrildi cunku "limit asiminda engelleme yok,
 * ucretlendir" karari (SS7) KISMI kapsami kacinilmaz kiliyor: gunluk hakki
 * 1 kalmis ogrenci 3 kahve eklerse 1'i bedava, 2'si ucretli olmali. Bool ile
 * bunu temsil etmenin tek yolu kaydi iki satira bolmekti - o da 60 saniyelik
 * geri almayi tek islem olmaktan cikarirdi.
 *
 * covered_by_package artik turetiliyor (Consumption::isCoveredByPackage),
 * sutunda tutulmuyor: ikinci bir dogruluk kaynagi olurdu - ayni gerekce
 * exam_result_subjects.net ve study_tables.status icin de verilmisti.
 *
 * foreignId()->constrained() BILEREK yok: SQLite'ta tabloyu bastan yazar ve
 * ham SQL indekslerini dusurur (SS10.5). Eklenen yalnizca bir tamsayi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumptions', function (Blueprint $table) {
            $table->unsignedInteger('covered_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('consumptions', function (Blueprint $table) {
            $table->dropColumn('covered_quantity');
        });
    }
};
