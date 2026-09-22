<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturaya paket tutari. total_amount TUKETIM toplami olarak kaliyor
 * (mevcut CSV ve ekranlar oyle okuyor); genel toplam modelde hesaplanir.
 *
 * Tutar, fatura ayinda BASLAYAN aboneliklerin subscriptions.price
 * toplami: fiyat basladigi ayda bir kez yazilir, aylara bolunmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_bills', function (Blueprint $table) {
            $table->decimal('package_amount', 10, 2)->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_bills', function (Blueprint $table) {
            $table->dropColumn('package_amount');
        });
    }
};
