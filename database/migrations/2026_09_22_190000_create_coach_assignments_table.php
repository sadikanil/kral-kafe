<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koc-ogrenci atamasi (Dalga 14).
 *
 * User::accessibleStudentIds() koc icin bu ana kadar BOS DIZI donuyordu -
 * yani koc hicbir ogrenciyi goremiyordu. Sinirin tek kaynagi orasi oldugu
 * icin bu tablo gelince liste, tekil kayit ve yetki kontrolu birlikte
 * dogru hale gelir.
 *
 * student_parent ile ayni bicim: iki yonlu belongsToMany, created_by ile
 * "kim atadi" izi. Coklu koc bilerek serbest - bir ogrencinin hem TYT hem
 * AYT kocu olabilir; unique kisit ayni CIFTIN tekrarini engeller, ikinci
 * bir kocu degil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Ayni atamayi iki kez gondermek ikinci satir olusturmamali.
            // Denetim uc tarafinda syncWithoutDetaching ile de yapiliyor;
            // kisit, formu iki kez gonderen tarayiciya karsi son duvar.
            $table->unique(['coach_id', 'student_id']);
        });

        PostgresSecurity::lockDown('coach_assignments');
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_assignments');
    }
};
