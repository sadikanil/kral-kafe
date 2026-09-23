<?php

use App\Support\Telefon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 18: telefonla giris.
 *
 * - email ve password NULL olabilir: yonetici kullaniciyi telefonla ekler,
 *   sifreyi kullanici ilk giriste belirler.
 * - phone tek bicime (10 hane) cekilir ve TEKIL olur: giris kimligi.
 *
 * Postgres'te ->change() KULLANILMIYOR: komsu subscription_status CHECK
 * kisitini dusurur (README SS10.2). Ham ALTER yalnizca NOT NULL'a dokunur.
 * SQLite'ta ALTER COLUMN yok; ->change() tabloyu yeniden yazar. users'ta ham
 * SQL ile kurulmus indeks yok, email tekilligini Laravel geri kuruyor (SS10.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
                $table->string('password')->nullable()->change();
            });
        }

        // Mevcut numaralari tek bicime cek. Gecersiz ya da cakisan numara
        // bosaltilir - tekil indeks aksi halde kurulamaz; yonetici formdan
        // yeniden girer.
        $gorulen = [];

        foreach (DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $satir) {
            $temiz = Telefon::normalize($satir->phone);

            if ($temiz !== null && isset($gorulen[$temiz])) {
                $temiz = null;
            }

            if ($temiz !== null) {
                $gorulen[$temiz] = true;
            }

            DB::table('users')->where('id', $satir->id)->update(['phone' => $temiz]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
        // NOT NULL geri konmuyor: bu migration'dan sonra eklenen telefonlu
        // kullanicilarin e-postasi/sifresi yok, kisit onlari reddederdi.
    }
};
