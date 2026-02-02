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
        Schema::create('stock_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('record_type', ['opening', 'closing']);
            $table->integer('verified_quantity');
            $table->integer('ai_suggested_quantity')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('recorded_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Index for querying stock by date and location
            $table->index(['location_id', 'recorded_at']);
            $table->index(['recorded_at', 'record_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_records');
    }
};
