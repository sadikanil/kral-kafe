<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paketler her ay kendiliginden yenilenir (18-21 Kasim 2026, isletme karari:
 * "iptal edilmedigi ya da degistirilmedigi surece her ay tekrar eder";
 * "paket ve adisyon her ayin basinda yenilensin").
 *
 * Eskiden her satir tek bir donemdi ve ay bitince paket dusuyordu; yonetici
 * yeni donemi "Paketi degistir" ile aciyordu. O akis her seferinde TAM
 * fiyatli yeni bir donem yazdi, birkac gunluk parcalar dahil (canlida 2 ayda
 * 3 kez Kral). Satir yine bir donemdir; yenileme bir sonraki donemi acar.
 *
 * - Donemler TAKVIM AYIdir: ayin 1'i - son gunu. Ay ortasinda baslayan donem
 *   ay sonunda biter ve tutari kalan gunlere gore orantilanir (kist).
 * - base_price: uyenin AYLIK tutari (indirim dahil). price o donemin
 *   tutaridir (kist donemde daha az). Yenileme base_price'i kullanir.
 * - auto_renew: donem bitince ayni paketle yeni ay acilir.
 *   Varsayilan FALSE: mevcut satirlarin hicbiri kendiliginden yenilenmez.
 *   Canlidaki karisik donemler duzeltilmeden yenilenseydi yanlis borclar
 *   her ay cogalirdi; acmak yonetimin (ya da duzeltme adiminin) isi.
 *   Yeni atamalar SubscriptionOpener'da acik yazar.
 * - renewed_from_id: bu donemi acan onceki donem. BENZERSIZ: gece isi ile
 *   sayfa acilisi ayni anda calissa da bir donem yalniz bir kez yenilenir.
 * - next_package_id / next_price: "sonraki donemden itibaren" paket
 *   degisikligi; yenileme bunlari kullanir.
 *
 * Kisitlar yalnizca Postgres'te ALTER TABLE ile (README SS10.5): SQLite'ta
 * kisit eklemek tabloyu bastan yazar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false);
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->unsignedBigInteger('next_package_id')->nullable();
            $table->decimal('next_price', 10, 2)->nullable();
            $table->decimal('base_price', 10, 2)->nullable();

            $table->unique('renewed_from_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_renewed_from_id_foreign FOREIGN KEY (renewed_from_id) REFERENCES subscriptions (id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_next_package_id_foreign FOREIGN KEY (next_package_id) REFERENCES packages (id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_next_package_id_foreign');
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_renewed_from_id_foreign');
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['renewed_from_id']);
            $table->dropColumn(['auto_renew', 'renewed_from_id', 'next_package_id', 'next_price', 'base_price']);
        });
    }
};
