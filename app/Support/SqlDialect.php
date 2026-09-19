<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * SQL parcalari icin surucu farkliliklarini tek yerde toplar.
 *
 * Proje yerelde SQLite, uretimde Postgres (Supabase) ile calisiyor; tarih
 * fonksiyonlari bu iki surucude ayni degil.
 */
class SqlDialect
{
    /**
     * Bir timestamp sutunundan yil ve ay ceken select ifadesi.
     *
     * Sonuc her zaman "year" ve "month" takma adlarini kullanir.
     */
    public static function yearMonth(string $driver, string $column): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Gecersiz sutun adi: {$column}");
        }

        return match ($driver) {
            'sqlite' => "CAST(strftime('%Y', {$column}) AS INTEGER) as year, "
                . "CAST(strftime('%m', {$column}) AS INTEGER) as month",
            'pgsql' => "EXTRACT(YEAR FROM {$column})::int as year, "
                . "EXTRACT(MONTH FROM {$column})::int as month",
            default => "YEAR({$column}) as year, MONTH({$column}) as month",
        };
    }
}
