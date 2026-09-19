<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Supabase'de public semasini disariya kapatir.
 *
 * Supabase, public semasini PostgREST uzerinden internete acar. Koruma iki
 * katmanli ve ikisi AYNI SEKILDE DEVRALINMAZ:
 *
 *   1. Yetkiler (GRANT) — 2026_09_19_120000 migration'indaki
 *      ALTER DEFAULT PRIVILEGES sayesinde yeni tablolara otomatik kapali gelir.
 *   2. RLS — Postgres'te "varsayilan RLS" diye bir sey YOKTUR. Her tabloda
 *      acikca acilmak zorundadir.
 *
 * Yani yeni bir tablo, yetkileri kapali ama RLS'i acik olmayan bir halde dogar.
 * Bu haliyle erisilemez, ama biri Supabase panelinden tek tikla yetki verirse
 * tablo aninda aciga cikar. Bu yardimci ikinci katmani da kapatir.
 *
 * Uygulama etkilenmez: Laravel'in bagladigi rol tablo sahibidir ve ayrica
 * rolbypassrls tasir (canli veritabaninda dogrulandi), yani RLS'i atlar.
 *
 * Kullanim — tablo olusturan HER migration'in sonunda:
 *
 *     PostgresSecurity::lockDown('study_tables', 'study_sessions');
 *
 * PostgresSecurityTest bunu zorunlu kilar: koruma migration'indan sonra gelen
 * ve lockDown cagirmayan her Schema::create testi kirmizi yapar.
 *
 * Bilerek down() karsiligi YOK. Mevcut koruma migration'inin down()'u
 * anon/authenticated'a GRANT ALL veriyor — yani bir rollback uretimde guvenlik
 * aciyor. Bu yardimci o hatayi tekrarlamaz: geri alinabilir olmasi gereken sey
 * tablonun kendisidir, korumasi degil.
 */
class PostgresSecurity
{
    public static function lockDown(string ...$tables): void
    {
        // Dogrulama surucuden ONCE: bozuk bir tablo adi yerelde de bagirmali,
        // yoksa hata ancak uretime cikinca fark edilir.
        foreach ($tables as $table) {
            self::assertPlainIdentifier($table);
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM anon, authenticated");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    private static function assertPlainIdentifier(string $table): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Gecersiz tablo adi: {$table}");
        }
    }
}
