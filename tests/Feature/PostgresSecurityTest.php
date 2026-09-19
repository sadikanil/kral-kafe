<?php

namespace Tests\Feature;

use App\Support\PostgresSecurity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Supabase'de public semasi PostgREST uzerinden internete acik.
 * 2026_09_19_120000 migration'i mevcut tablolari kapatti, ama o migration
 * pg_tables'i CALISMA ANINDA fotografliyor: sonradan olusan tablolari gormez.
 *
 * ALTER DEFAULT PRIVILEGES yalnizca yetki katmanini geleceğe tasiyor;
 * Postgres'te "varsayilan RLS" diye bir sey yok. Yani her yeni tablo
 * acikca kilitlenmek zorunda.
 */
class PostgresSecurityTest extends TestCase
{
    public function test_it_produces_no_sql_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $sorgular = [];
        DB::listen(function ($q) use (&$sorgular) {
            $sorgular[] = $q->sql;
        });

        PostgresSecurity::lockDown('herhangi_bir_tablo');

        $this->assertSame([], $sorgular, 'SQLite surucusunde hicbir SQL uretilmemeli');
    }

    public function test_it_rejects_a_table_name_that_is_not_a_plain_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PostgresSecurity::lockDown('users"; drop table users; --');
    }

    public function test_it_accepts_several_tables_at_once(): void
    {
        $this->expectNotToPerformAssertions();

        PostgresSecurity::lockDown('study_tables', 'study_sessions', 'student_parent');
    }

    /**
     * Asil muhafiz: tablo olusturan her migration korumayi cagirmak zorunda.
     *
     * Bu testin kirmizi olmasi, yeni bir tablonun anon erisimine acik
     * dogdugunu gosterir - Supabase panelinde bile hemen goze carpmayan bir hata.
     */
    public function test_every_migration_that_creates_a_table_locks_it_down(): void
    {
        $eksik = [];

        // Koruma migration'indan ONCE gelen tablolar zaten o migration'in
        // supurmesiyle kapatildi ve uretimde kostu. Kural yalnizca ondan
        // SONRA olusturulan tablolar icin gecerli.
        $korumaMigrationi = '2026_09_19_120000';

        foreach (glob(database_path('migrations/*.php')) as $dosya) {
            $icerik = file_get_contents($dosya);
            $ad = basename($dosya);

            if (str_contains($ad, 'secure_public_schema')) {
                continue;
            }

            if (strcmp(substr($ad, 0, strlen($korumaMigrationi)), $korumaMigrationi) <= 0) {
                continue;
            }

            if (! preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", $icerik, $m)) {
                continue;
            }

            foreach ($m[1] as $tablo) {
                if (! str_contains($icerik, 'PostgresSecurity::lockDown')) {
                    $eksik[] = "{$ad} -> {$tablo}";
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($eksik)),
            "Bu migration'lar tablo olusturuyor ama PostgresSecurity::lockDown() cagirmiyor; "
            . 'Supabase\'de yeni tablolar RLS KAPALI dogar'
        );
    }
}
