<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * role sutununu enum('student','admin') olmaktan cikarip duz metne cevirir.
 *
 * Yeni rol her eklendiginde sutun tipini degistirmek iki surucude de tehlikeli:
 *
 *   - Postgres'te enum()->change() "alter column ... check (...)" uretir ve
 *     SQLSTATE[42601] ile migration'i YARIDA cokertir.
 *   - string()->change() gecer ama Postgres eski CHECK kisitini yerinde birakir;
 *     sonraki 'coach' yazma denemesi SQLSTATE[23514] verir. Bu yuzden kisit
 *     asagida ACIKCA dusuruluyor.
 *
 * Dogrulama artik App\Enums\Role uzerinden PHP tarafinda yapiliyor.
 *
 * Yan etki (SQLite): ->change() tabloyu bastan yazarken komsu
 * subscription_status sutununun CHECK kisiti da dusuyor. Postgres'te duruyor.
 * Kabul edilebilir cunku o alan da UserController'da dogrulaniyor; ama bu
 * yuzden "SQLite'ta gecti" bir subscription_status degeri icin kanit degildir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('student')->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        }
    }

    public function down(): void
    {
        // Bilerek geri alinmiyor. enum()'a donmek Postgres'te SQLSTATE[42601]
        // ile coker; ustelik bu migration'dan sonra olusmus koc/veli satirlari
        // dar kisita sigmaz. Geri alinmasi gereken sey sutun degil, onu kullanan
        // ozelliktir.
    }
};
