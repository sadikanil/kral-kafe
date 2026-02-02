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
        Schema::create('stock_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->uuid('batch_id'); // Groups photos from same session
            $table->enum('record_type', ['opening', 'closing']);
            $table->string('photo_path', 255);
            $table->json('ai_analysis')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Index for batch processing
            $table->index(['batch_id']);
            $table->index(['location_id', 'uploaded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_photos');
    }
};
