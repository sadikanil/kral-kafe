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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['student', 'admin'])->default('student')->after('email');
            $table->enum('subscription_status', ['active', 'inactive', 'suspended'])->default('active')->after('role');
            $table->date('subscription_start')->nullable()->after('subscription_status');
            $table->date('subscription_end')->nullable()->after('subscription_start');
            $table->string('phone', 20)->nullable()->after('subscription_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'subscription_status', 'subscription_start', 'subscription_end', 'phone']);
        });
    }
};
