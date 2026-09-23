<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dalga 19: paket seviyeleri.
 *
 * Haklar BAYRAKLARDAN gelir (App\Support\Entitlements); tier yalnizca
 * etiket (1 Standart, 2 Orta, 3 Kral). "Sadece deneme" ve "deneme kulubu
 * eki" gibi paketlerin tier'i yok, bu yuzden NULL olabilir.
 *
 * is_addon: ana paketin USTUNE eklenen paket (ornegin Tier 2 + deneme
 * kulubu). Haklar ogrencinin tum aktif paketlerinin birlesimi.
 *
 * Yalnizca sutun EKLENIYOR: ->change() yok, Postgres CHECK tuzagi yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier')->nullable()->after('name');
            $table->boolean('includes_exam_club')->default(false)->after('includes_coaching');
            $table->boolean('includes_private_lessons')->default(false)->after('includes_exam_club');
            $table->boolean('is_addon')->default(false)->after('includes_private_lessons');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['tier', 'includes_exam_club', 'includes_private_lessons', 'is_addon']);
        });
    }
};
