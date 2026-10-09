<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->timestamp('mikrotik_last_checked_at')->nullable()->after('mikrotik_password');
            $table->enum('mikrotik_last_check_status', ['connected', 'auth_failed', 'unreachable'])->nullable()->after('mikrotik_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn(['mikrotik_last_checked_at', 'mikrotik_last_check_status']);
        });
    }
};
