<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase'de public semasini disariya kapatir.
 *
 * Supabase, public semasini PostgREST uzerinden internete acar ve yeni
 * olusturulan tablolara "anon" ile "authenticated" rolleri icin tam yetki
 * verir. Bu roller herkese acik anon anahtariyla kullanilabildigi icin,
 * onlem alinmazsa anahtari eline gecen biri users (sifre hash'leriyle),
 * sessions ve password_reset_tokens tablolarini okuyabilir, hatta silebilir.
 *
 * Bu uygulama PostgREST kullanmiyor; Laravel dogrudan Postgres'e baglaniyor
 * ve tablo sahibi olan "postgres" rolu RLS'i atlar. Dolayisiyla erisimi
 * tamamen kapatmak uygulamayi etkilemez.
 *
 * Iki katman birden uygulanir:
 *   1. RLS acilir ve HICBIR politika tanimlanmaz -> satirlara erisim yok.
 *   2. Yetkiler geri alinir -> RLS'e tabi olmayan TRUNCATE de kapanir.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->publicTables() as $table) {
            DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM anon, authenticated");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
        }

        // Bundan sonra olusacak tablolar da varsayilan olarak kapali gelsin.
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated');

        foreach ($this->publicTables() as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
            DB::statement("GRANT ALL ON TABLE public.\"{$table}\" TO anon, authenticated");
        }
    }

    /**
     * @return array<int,string>
     */
    private function publicTables(): array
    {
        return array_map(
            fn ($row) => $row->tablename,
            DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename")
        );
    }
};
