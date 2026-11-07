<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teknik borc (7 Kasim 2026, README SS4.2).
 *
 * 1. study_sessions.anomaly_seen_at / anomaly_seen_by: sure asimi
 *    anomalisine "gordum" isareti. Eskiden canli ekran yalniz son 7 gunu
 *    gosteriyordu ve bakilmayan anomali sessizce kayboluyordu; artik
 *    gorulene kadar kalir.
 *
 * 2. Dalga 2'de ertelenen uc study_tables sutunu:
 *    - assigned_student_id: sabit uye. Yalnizca bilgi/uyari, kimseyi
 *      engellemez (StudyTable::assignedStudent).
 *    - location_id YERINE area (bolum: "Ust kat", "Sessiz oda"). locations
 *      tablosu raf/dolap/buzdolabi, yani stok yeri; Dalga 26'dan beri stok
 *      en dolu yerden dusuyor ve yer QR'lari kalkti. Masayi bir rafa baglamanin
 *      isi kalmadi; ihtiyac masalari bolume gore gruplamak.
 *    - status BILEREK yok: dolu/bos acik oturumdan turetiliyor; ikinci bir
 *      dogruluk kaynagi unutulan bir guncellemede yalan soylerdi.
 *
 * foreignId()->constrained() KULLANILMADI: SQLite'ta kisit eklemek tabloyu
 * bastan yazar ve study_sessions'in ham SQL kismi indeksini dusurur (README
 * SS10.5). Kisitlar yalnizca Postgres'te (canli) ALTER TABLE ile eklenir;
 * orada indekse dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->timestamp('anomaly_seen_at')->nullable();
            $table->unsignedBigInteger('anomaly_seen_by')->nullable();
        });

        Schema::table('study_tables', function (Blueprint $table) {
            $table->string('area', 40)->nullable();
            $table->unsignedBigInteger('assigned_student_id')->nullable();
        });

        // Eski canli ekran yalniz son 7 gunu gosteriyordu; daha eskiler o
        // pencereden zaten dusmustu. Onlari "gorulmus" sayariz (kim bos =
        // sistem), yoksa ekran ilk acilista aylarin anomalisiyle dolar.
        DB::table('study_sessions')
            ->where('end_reason', 'over_limit')
            ->whereNull('anomaly_seen_at')
            ->where('ended_at', '<', now()->subDays(7))
            ->update(['anomaly_seen_at' => now()]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE study_sessions ADD CONSTRAINT study_sessions_anomaly_seen_by_foreign FOREIGN KEY (anomaly_seen_by) REFERENCES users (id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE study_tables ADD CONSTRAINT study_tables_assigned_student_id_foreign FOREIGN KEY (assigned_student_id) REFERENCES users (id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE study_tables DROP CONSTRAINT IF EXISTS study_tables_assigned_student_id_foreign');
            DB::statement('ALTER TABLE study_sessions DROP CONSTRAINT IF EXISTS study_sessions_anomaly_seen_by_foreign');
        }

        Schema::table('study_tables', function (Blueprint $table) {
            $table->dropColumn(['area', 'assigned_student_id']);
        });

        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['anomaly_seen_at', 'anomaly_seen_by']);
        });
    }
};
