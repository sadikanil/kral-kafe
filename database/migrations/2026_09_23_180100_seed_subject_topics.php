<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dalga 30b: YKS konu listesi (475 konu, 21 ders).
 *
 * Kaynak: database/data/yks_konular.json. Kapsam MEB'in 2026 YKS kazanim
 * belgesi (OSYM kilavuzu bu belgeye atif yapar); adlandirma koclarin
 * kullandigi yaygin listelerden. Kaynak adresleri dosyanin icinde.
 *
 * Tekrar calisirsa cift satir dogmaz: unique(subject_id, name) +
 * insertOrIgnore.
 */
return new class extends Migration
{
    public function up(): void
    {
        $veri = json_decode(file_get_contents(database_path('data/yks_konular.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($veri['subjects'] as $kod => $konular) {
            $dersId = DB::table('subjects')->where('code', $kod)->value('id');

            if ($dersId === null) {
                continue;
            }

            DB::table('subject_topics')->insertOrIgnore(array_map(fn ($ad, $sira) => [
                'subject_id' => $dersId,
                'name' => $ad,
                'sort_order' => $sira + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], $konular, array_keys($konular)));
        }
    }

    public function down(): void
    {
        DB::table('subject_topics')->delete();
    }
};
