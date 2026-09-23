<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kullanici silinince stok gecmisi kalir (QA 31, 32).
 *
 * Eski uc yazar sutunu kullaniciya ya CASCADE (stock_records.admin_id,
 * stock_photos.admin_id: yoneticiyi silmek kaydettigi butun sayimlari ve
 * fotograflari da siliyordu) ya da kuralsiz (discrepancy_logs.resolved_by:
 * tutarsizlik cozmus yonetici hic silinemiyor, panel 500 veriyordu) bagliydi.
 * Yenilerde her *_by sutunu gibi nullOnDelete: kayit kalir, yazari bos olur.
 * Ekranlar yazari olmayan kaydi zaten bekliyor ('-', 'bilinmeyen yonetici').
 *
 * Sayim gecmisi ayrica "son sayim" hatirlatmasinin da kaynagi
 * (StockRecord::max('recorded_at')); silinen sayim o tarihi geri alirdi.
 *
 * Eski migration'lar canlida calisti; onlari degil kisitlari degistiriyoruz.
 *
 * SQLite'ta (yerel ve test) FK degisikligi tabloyu yeniden kurar ve bu
 * kurulum enum'un CHECK'ini duser: record_type duz varchar kalir, canlinin
 * reddettigi deger yerelde gecerdi. O yuzden SQLite'ta enum son kurulumda
 * yeniden tanimlanir. Postgres'te CHECK zaten yerinde; orada enum ->change()
 * gecersiz ALTER uretirdi, dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['stock_records', 'stock_photos'] as $tablo) {
            Schema::table($tablo, function (Blueprint $table) {
                $table->dropForeign(['admin_id']);
            });

            Schema::table($tablo, function (Blueprint $table) {
                $table->foreignId('admin_id')->nullable()->change();
                $table->foreign('admin_id')->references('id')->on('users')->nullOnDelete();
                $this->keepRecordTypeCheck($table);
            });
        }

        Schema::table('discrepancy_logs', function (Blueprint $table) {
            $table->dropForeign(['resolved_by']);
        });

        Schema::table('discrepancy_logs', function (Blueprint $table) {
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $this->keepRecordTypeCheck($table);
        });
    }

    /**
     * Kisitlar eski davranisina doner. admin_id NULLABLE kalir: bu arada
     * yazari silinmis bir kayit varsa NOT NULL'a donmek ya patlar ya da o
     * kaydi silmeyi gerektirirdi - geri almak gecmisi yok etmemeli.
     */
    public function down(): void
    {
        foreach (['stock_records', 'stock_photos'] as $tablo) {
            Schema::table($tablo, function (Blueprint $table) {
                $table->dropForeign(['admin_id']);
            });

            Schema::table($tablo, function (Blueprint $table) {
                $table->foreign('admin_id')->references('id')->on('users')->cascadeOnDelete();
                $this->keepRecordTypeCheck($table);
            });
        }

        Schema::table('discrepancy_logs', function (Blueprint $table) {
            $table->dropForeign(['resolved_by']);
        });

        Schema::table('discrepancy_logs', function (Blueprint $table) {
            $table->foreign('resolved_by')->references('id')->on('users');
            $this->keepRecordTypeCheck($table);
        });
    }

    /**
     * Yeniden kurulan SQLite tablosuna enum CHECK'ini geri koyar. Tanim ilk
     * migration'daki ile ayni (NOT NULL, varsayilansiz); ->change() tam tanim ister.
     */
    private function keepRecordTypeCheck(Blueprint $table): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $table->enum('record_type', ['opening', 'closing'])->change();
        }
    }
};
