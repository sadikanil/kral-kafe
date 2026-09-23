<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 30c: takvimli plan.
 *
 * Plan maddesi artik bir GUNE ait (plan_date). week_start ve period='week'
 * yazilmaya devam eder: haftalik ilerleme, koc listesi ve haftalik rapor
 * onlardan okuyor ve degismeden calismali.
 *
 * exam_event_id: ogrencinin serbest denemeyi koydugu gun (karar, 23 Eyl).
 *
 * student_commitments: okul, dershane, distaki ozel ders - haftalik sabit
 * program; takvimde her hafta dolu saat olarak gorunur. Gun basina bir
 * satir ("Pzt-Cum" bes satirdir) ki tek gunu silmek mumkun olsun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->date('plan_date')->nullable();
            $table->foreignId('subject_topic_id')->nullable()->constrained('subject_topics')->nullOnDelete();
            $table->string('starts_at', 5)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->foreignId('exam_event_id')->nullable()->constrained('exam_events')->cascadeOnDelete();

            $table->index(['student_id', 'plan_date']);
        });

        // Eski haftalik/aylik madde: donemin ilk gunune.
        DB::table('study_plan_items')->whereNull('plan_date')->update(['plan_date' => DB::raw('week_start')]);

        Schema::create('student_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('title', 100)->nullable();
            $table->unsignedTinyInteger('weekday');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'weekday']);
        });

        PostgresSecurity::lockDown('student_commitments');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_commitments');

        Schema::table('study_plan_items', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'plan_date']);
            $table->dropConstrainedForeignId('exam_event_id');
            $table->dropConstrainedForeignId('subject_topic_id');
            $table->dropColumn(['plan_date', 'starts_at', 'duration_minutes']);
        });
    }
};
