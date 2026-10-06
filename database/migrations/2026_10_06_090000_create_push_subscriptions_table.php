<?php

use App\Support\PostgresSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telefon bildirimi (6 Ekim 2026): tarayicinin verdigi abonelik.
 *
 * Her satir bir cihazdaki bir tarayici. endpoint tarayici saglayicisinin
 * (Google, Apple, Mozilla) adresi; p256dh ve auth icerigi o cihaza
 * sifrelemek icin. endpoint 500 karakteri asabiliyor; tekillik ozeti
 * (sha256) uzerinden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        PostgresSecurity::lockDown('push_subscriptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
