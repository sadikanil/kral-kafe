<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calisma masalari. locations'tan AYRI bir kavram.
 *
 * locations raf/dolap/buzdolabi demek ve stok sayimina bagli; masa ise
 * ogrencinin oturdugu yer. Ayni tabloya sikistirmak stok sayim ekranina masa
 * satirlari, canli ekrana buzdolabi satirlari dusururdu.
 *
 * Tablo adi 'tables' DEGIL: Schema::create('tables', function (Blueprint $table)
 * okunmaz bir satir, Table modeli de HTML tablo kavramiyla karisir.
 *
 * Kaynak belgedeki status / assigned_student_id / location_id sutunlari bilerek
 * ERTELENDI:
 *   - status (bos|dolu) study_sessions'tan turetilebilir; sutunda tutmak ikinci
 *     bir dogruluk kaynagi yaratir ve ikisi kacinilmaz olarak ayrisir.
 *   - assigned_student_id'nin anlami paket ozelligiyle (Dalga 7) geliyor;
 *     simdi eklenirse hicbir sey yazmaz ve okumaz.
 *   - location_id, GUNCELLEMELER.md §11 #10'daki "raf ile masa fiziksel olarak
 *     ortusuyor mu" sorusu cevaplanmadan tasarlanamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('qr_code', 32)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        PostgresSecurity::lockDown('study_tables');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_tables');
    }
};
