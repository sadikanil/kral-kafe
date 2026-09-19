<?php

namespace Tests\Unit;

use App\Support\SqlDialect;
use PHPUnit\Framework\TestCase;

class SqlDialectTest extends TestCase
{
    public function test_sqlite_uses_strftime(): void
    {
        $sql = SqlDialect::yearMonth('sqlite', 'consumed_at');

        $this->assertStringContainsString("strftime('%Y', consumed_at)", $sql);
        $this->assertStringContainsString("strftime('%m', consumed_at)", $sql);
    }

    public function test_postgres_uses_extract(): void
    {
        $sql = SqlDialect::yearMonth('pgsql', 'consumed_at');

        $this->assertStringContainsString('EXTRACT(YEAR FROM consumed_at)', $sql);
        $this->assertStringContainsString('EXTRACT(MONTH FROM consumed_at)', $sql);
    }

    public function test_mysql_uses_year_and_month_functions(): void
    {
        $sql = SqlDialect::yearMonth('mysql', 'consumed_at');

        $this->assertStringContainsString('YEAR(consumed_at)', $sql);
        $this->assertStringContainsString('MONTH(consumed_at)', $sql);
    }

    public function test_every_dialect_aliases_the_columns_as_year_and_month(): void
    {
        foreach (['sqlite', 'pgsql', 'mysql', 'mariadb'] as $driver) {
            $sql = SqlDialect::yearMonth($driver, 'consumed_at');

            $this->assertStringContainsString('as year', $sql, "{$driver} icin year takma adi yok");
            $this->assertStringContainsString('as month', $sql, "{$driver} icin month takma adi yok");
        }
    }

    public function test_it_rejects_a_column_name_that_is_not_a_plain_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SqlDialect::yearMonth('sqlite', 'consumed_at); drop table users; --');
    }
}
