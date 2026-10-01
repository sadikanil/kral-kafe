<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koclar, odev ve ozel ders ogretmeni (1 Ekim 2026).
 *
 *   users.is_coach         - ana rolu veli/ogretmen olan birine koc yetkisi
 *                            (ornek: hem velisi oldugu cocugu hem ozel ders
 *                            verdigi ogrencileri olan matematik ogretmeni).
 *                            Rol sutunu TEK kalir (README SS10.2); koc
 *                            yetkisi ek bayrak.
 *   users.coach_subject    - kocun bransi ("Matematik", "Fizik"); ogrenci
 *                            "kim, hangi dersten" gorur.
 *   study_plan_items.tag   - plan | homework. Yonetici disindaki koclar
 *                            yalnizca odev ekler.
 *   private_lesson_slots.teacher_id / branch - dersi kim, hangi dersten
 *                            veriyor. Koc kendi derslerini takip eder.
 *   packages.lesson_count  - ozel ders paketinde donem basina ders sayisi.
 *
 * Hepsi eklemeli ve varsayilanli: canlida duran eski kod bu sutunlari
 * bilmeden calismaya devam eder (migration'lar koddan once uygulaniyor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_coach')->default(false);
            $table->string('coach_subject', 60)->nullable();
        });

        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->string('tag', 20)->default('plan');
        });

        Schema::table('private_lesson_slots', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('branch', 60)->nullable();
            $table->index('teacher_id');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('lesson_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('lesson_count');
        });

        Schema::table('private_lesson_slots', function (Blueprint $table) {
            $table->dropIndex(['teacher_id']);
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn('branch');
        });

        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->dropColumn('tag');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_coach', 'coach_subject']);
        });
    }
};
