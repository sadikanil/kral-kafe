<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deneme sinavi takvimi - kafe geneli planlanmis denemeler.
 *
 * Bu tablo SONUC tutmaz: "hangi gun hangi deneme var" sorusuna cevap verir.
 * Ogrenci basina sonuc (D/Y/net) FEATURE 6'nin isi ve ayri tabloda
 * (mock_exams / mock_exam_results) gelecek; ikisini karistirmak her sonuc
 * satirinda tarih/ad tekrarina yol acardi.
 *
 * exam_date DATE, saat ayri ve string: deneme "27 Eylul 10:00" diye
 * planlanir, bir UTC ani olarak degil. Timestamp kullansaydik gun siniri
 * saat dilimine gore kayabilirdi (bkz. LocalDay).
 *
 * exam_type duz string + Rule::enum (bkz. Role enum'undaki gerekce; DB
 * seviyesinde enum/CHECK yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('exam_type', 10);
            $table->date('exam_date');
            $table->string('starts_at', 5)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('exam_date');
        });

        PostgresSecurity::lockDown('exam_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_events');
    }
};
