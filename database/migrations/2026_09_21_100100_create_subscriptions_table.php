<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ogrencinin paket gecmisi ve odeme kayitlari.
 *
 * subscriptions.price: acilis anindaki fiyat, KATALOGDAN BAGIMSIZ. Fatura
 * (monthly_bills.package_amount) buradan okur.
 *
 * payment_status saklanir ama 'overdue' turetilir: pending + vade gecti.
 * Bkz. Subscription::syncPaymentStatus(). Duz string + Rule::enum.
 *
 * package_id restrictOnDelete: abonelik gecmisi olan paket silinemez,
 * kapatilir (is_active=false) - masalarla ayni karar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('price', 10, 2);
            $table->string('payment_status', 10)->default('pending');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'starts_on']);
            $table->index('payment_status');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('paid_at');
            $table->string('method', 20)->default('cash');
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('paid_at');
        });

        PostgresSecurity::lockDown('subscriptions', 'payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
    }
};
