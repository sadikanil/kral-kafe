<?php

namespace App\Support;

/**
 * SQL parcalari icin surucu farkliliklarini tek yerde toplar.
 *
 * Proje yerelde SQLite, uretimde Postgres (Supabase) ile calisiyor; tarih
 * fonksiyonlari bu iki surucude ayni degil.
 */
class SqlDialect
{
    /**
     * Metin aramasi icin harf duyarsiz karsilastirma operatoru.
     *
     * Postgres'te LIKE buyuk/kucuk harfe DUYARLIDIR; SQLite ve MySQL'de degildir.
     * Duz LIKE birakilirsa arama uretimde sessizce sonuc dondurmez.
     */
    public static function likeOperator(string $driver): string
    {
        return $driver === 'pgsql' ? 'ilike' : 'like';
    }
}
