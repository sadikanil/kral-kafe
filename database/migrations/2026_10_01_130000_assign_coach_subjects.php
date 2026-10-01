<?php

use App\Support\StudentNameMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kafenin koclari (1 Ekim 2026, sahibin karari):
 *
 *   Cahit Atılğan  - yonetici, fizik ogretmeni (tum plan ve ozel ders onda)
 *   İbrahim Acar   - veli + koc, matematik ogretmeni (atandigi ogrencilere
 *                    yalnizca odev verir, kendi ozel derslerini takip eder)
 *
 * Ada gore bulunur (Turkce harf duzeyinde normallestirilmis); kullanici
 * yoksa hicbir sey yapmaz - yonetici kullanici formundan ekler (Rol: Veli,
 * "Koçluk yetkisi de var", Branş: Matematik). Yalnizca UPDATE; iki kez
 * calismasi ayni sonuca varir. Rol DEGISMEZ: veli veli kalir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kullanicilar = DB::table('users')->get(['id', 'name', 'role']);

        foreach ($kullanicilar as $u) {
            $ad = StudentNameMatcher::normalize((string) $u->name);

            if ($ad === 'cahit atilgan' && $u->role === 'admin') {
                DB::table('users')->where('id', $u->id)->update(['coach_subject' => 'Fizik']);
            }

            if ($ad === 'ibrahim acar' && in_array($u->role, ['parent', 'teacher', 'coach'], true)) {
                DB::table('users')->where('id', $u->id)->update([
                    'is_coach' => $u->role !== 'coach',
                    'coach_subject' => 'Matematik',
                ]);
            }
        }
    }

    public function down(): void
    {
        // Veri adimi; sutunlar bir onceki migration'la birlikte duser.
    }
};
