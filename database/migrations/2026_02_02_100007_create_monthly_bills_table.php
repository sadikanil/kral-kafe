<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monthly_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('bill_month'); // First day of month
            $table->integer('total_items')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('status', ['pending', 'sent', 'exported'])->default('pending');
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'bill_month']);
            $table->index(['bill_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_bills');
    }
};
