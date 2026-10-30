<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adisyon tahsilati (DEV-507, 27 Ekim 2026).
 *
 * monthly_bills'e odeme durumu ve son odemenin ozeti; her odeme ve geri
 * alma bill_payments'ta (kim, ne zaman, ne kadar). Ozet sutunlar
 * bill_payments'tan turetilir (MonthlyBill::syncPayment) - listeyi ve
 * CSV'yi tek sorguda okumak icin tutuluyor.
 *
 * Mevcut adisyonlar "unpaid" baslar (varsayilan deger eklenirken mevcut
 * satirlara da yazilir). Durum string: Postgres'te enum degistirmek
 * migration'i cokertiyor (README SS10.2). Sutun ekleme ve yeni tablo
 * MySQL, SQLite ve Postgres'te ayni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_bills', function (Blueprint $table) {
            $table->string('payment_status', 10)->default('unpaid');
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note', 255)->nullable();

            $table->index('payment_status');
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_bill_id')->constrained('monthly_bills')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            $table->date('paid_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('monthly_bill_id');
        });

        PostgresSecurity::lockDown('bill_payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');

        Schema::table('monthly_bills', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['payment_status', 'paid_amount', 'paid_at', 'payment_method', 'payment_note']);
        });
    }
};
