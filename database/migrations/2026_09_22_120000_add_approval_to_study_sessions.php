<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oturum onay akisi (Dalga 9).
 *
 * Biten oturum artik dogrudan gorunmuyor; yoneticinin onay kuyruguna dusuyor.
 * Onaylanana kadar veli goremez ve hicbir toplama girmez.
 *
 * string(10) + PHP tarafinda dogrulama, enum DEGIL: Postgres'te enum'u
 * degistirmek migration'i cokertiyor (bkz. README SS10.2).
 *
 * MEVCUT SATIRLAR ONAYLI YAZILIYOR. Varsayilan 'pending' yeni oturumlar icin
 * dogru ama gecmise uygulanirsa bugune kadar gorunen sureler bir anda kaybolur,
 * ogrencinin serisi ve hedef cubugu sebepsiz sifirlanir. Onay ileriye donuk bir
 * kural; gecmisi yeniden yazmaz.
 *
 * lockDown() cagrilmiyor: tablo yeni degil, korumasi Dalga 3'te kuruldu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->string('approval_status', 10)->default('pending')->after('end_reason');
            $table->foreignId('reviewed_by')->nullable()->after('approval_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('rejection_reason', 255)->nullable()->after('reviewed_at');

            $table->index('approval_status');
        });

        // Migration calistigi anda var olan her satir gecmise aittir.
        DB::table('study_sessions')->update(['approval_status' => 'approved']);

        $this->kismiIndeksiTazele();
    }

    /**
     * Kismi tekil indeksi yeniden kurar.
     *
     * SQLite'ta foreignId()->constrained() ALTER TABLE ile eklenemez; Laravel
     * tabloyu bastan yazar. O sirada YALNIZCA kendi bildigi indeksleri geri
     * kuruyor - Dalga 3'te ham SQL ile acilan
     * "UNIQUE (student_id) WHERE ended_at IS NULL" indeksi WHERE yan tumcesini
     * KAYBEDIYOR ve kosulsuz bir tekil indekse donusuyor. Sonucu felaket:
     * "ogrenci basina tek ACIK oturum" kurali "ogrenci basina tek oturum"
     * olur, ogrenci ikinci kez hic calismaya baslayamaz.
     *
     * Ayni aile: SS9.1.1'de ->change()'in komsu sutunun CHECK kisitini
     * dusurmesi. SQLite'ta tabloyu yeniden yazan her migration, ham SQL ile
     * kurulmus her seyi geri koymak zorunda.
     *
     * Postgres'te ALTER TABLE indekse dokunmaz; orada bu adim zararsiz bir
     * yeniden olusturmadir.
     */
    private function kismiIndeksiTazele(): void
    {
        DB::statement('DROP INDEX IF EXISTS study_sessions_tek_acik_oturum');
        DB::statement(
            'CREATE UNIQUE INDEX study_sessions_tek_acik_oturum
             ON study_sessions (student_id) WHERE ended_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['approval_status', 'reviewed_at', 'rejection_reason']);
        });
    }
};
