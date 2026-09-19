<?php

namespace Tests\Feature;

use App\Support\SqlDialect;
use PDO;
use Tests\TestCase;

/**
 * Uretimde Supabase (Postgres) kullanilacak. Yerelde Postgres sunucusu
 * olmadigi icin bu testler yapilandirma ve SQL uretimi seviyesinde dogrular.
 */
class PostgresReadinessTest extends TestCase
{
    public function test_prepared_statement_emulation_is_off_by_default(): void
    {
        $options = config('database.connections.pgsql.options');

        $this->assertIsArray($options, 'pgsql baglantisinda options anahtari yok');
        $this->assertArrayNotHasKey(
            PDO::ATTR_EMULATE_PREPARES,
            $options,
            'Emulasyon acikken PDO, Laravel\'in int\'e cevirdigi boolean\'lari ciplak 1/0 '
            . 'olarak gomuyor ve Postgres "column is of type boolean but expression is of '
            . 'type integer" (42804) veriyor. Gercek Supabase uzerinde dogrulandi: '
            . 'is_active yazan her migration ve her kayit duser.'
        );
    }

    public function test_emulation_can_still_be_enabled_when_a_deployment_needs_it(): void
    {
        // Transaction pooler (6543) sunucu tarafi prepared statement tasimadigi
        // icin orada emulasyon gerekir; ama o zaman boolean sorunu da birlikte
        // gelir. Bu yuzden acik bir tercih olarak birakildi, varsayilan degil.
        putenv('DB_EMULATE_PREPARES=true');
        $this->refreshApplication();

        $options = config('database.connections.pgsql.options');

        $this->assertTrue($options[PDO::ATTR_EMULATE_PREPARES] ?? false);

        putenv('DB_EMULATE_PREPARES');
    }

    public function test_postgres_search_uses_a_case_insensitive_operator(): void
    {
        $this->assertSame('ilike', SqlDialect::likeOperator('pgsql'));
    }

    public function test_other_drivers_keep_plain_like(): void
    {
        $this->assertSame('like', SqlDialect::likeOperator('sqlite'));
        $this->assertSame('like', SqlDialect::likeOperator('mysql'));
        $this->assertSame('like', SqlDialect::likeOperator('mariadb'));
    }

    public function test_searches_do_not_hardcode_the_like_operator(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/**/*.php'), GLOB_BRACE) ?: [] as $file) {
            if (preg_match("/['\"]like['\"]/i", file_get_contents($file))) {
                $offenders[] = str_replace(app_path() . '/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Bu controller'lar 'like' operatorunu sabitlemis; Postgres'te LIKE harf duyarli "
            . 'oldugu icin arama sonuc dondurmez. SqlDialect::likeOperator() kullanin.'
        );
    }
}
