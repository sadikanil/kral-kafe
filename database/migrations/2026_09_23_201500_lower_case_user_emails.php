<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-posta tek bicime: kirpilmis, kucuk harf (QA bug 2).
 *
 * User::email artik boyle yaziyor, ama onceden buyuk harfle kaydedilmis
 * hesaplar girise hic eslesmiyordu. Kucuk harfli hali baska bir satirda da
 * varsa ikisine de dokunulmaz: tekil indeks patlardi ve hangisinin dogru hesap
 * oldugunu yalnizca yonetici bilir.
 *
 * Tek SQL cumlesi, bilerek: canliya --pretend ciktisiyla elle uygulaniyor ve
 * PHP dongusu o ciktida gorunmezdi. Hem Postgres hem SQLite'ta ayni calisir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users
            SET email = lower(trim(email))
            WHERE email IS NOT NULL
              AND email <> lower(trim(email))
              AND NOT EXISTS (
                  SELECT 1 FROM users AS diger
                  WHERE diger.id <> users.id
                    AND lower(trim(diger.email)) = lower(trim(users.email))
              )
            SQL);
    }

    public function down(): void
    {
        // Geri alinmiyor: eski buyuk harfli yazim zaten kaybolmasi istenen bilgi.
    }
};
