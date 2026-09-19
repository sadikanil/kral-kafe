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
    public function test_pgsql_connection_emulates_prepared_statements(): void
    {
        $options = config('database.connections.pgsql.options');

        $this->assertIsArray($options, 'pgsql baglantisinda options anahtari yok');
        $this->assertTrue(
            $options[PDO::ATTR_EMULATE_PREPARES] ?? false,
            'Supabase transaction pooler (6543) sunucu tarafi prepared statement tasimiyor; '
            . 'emulasyon acik olmazsa her istek SQLSTATE[42P05] ile duser'
        );
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
