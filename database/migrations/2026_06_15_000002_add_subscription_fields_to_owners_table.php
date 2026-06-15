<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->timestamp('subscription_starts_at')->nullable()->after('mikrotik_password');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_starts_at');
            $table->boolean('is_active')->default(true)->after('subscription_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn(['subscription_starts_at', 'subscription_expires_at', 'is_active']);
        });
    }
};
