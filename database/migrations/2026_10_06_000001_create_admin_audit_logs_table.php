<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Super Admin audit trail: every sensitive admin action (suspend/activate,
     * renew/extend, plan & feature changes, broadcasts, request decisions)
     * with who, what, why and when. Names are snapshotted so the record
     * survives the admin or business being deleted.
     *
     * Also adds owner-scoped indexes the admin 360° view leans on — SQLite
     * does not index foreign keys automatically.
     */
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_name', 120)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->string('owner_name', 160)->nullable();
            $table->string('action', 60);
            $table->string('description', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['owner_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->index(['owner_id', 'created_at'], 'hotspot_users_owner_created_index');
        });
        Schema::table('shared_sessions', function (Blueprint $table) {
            $table->index(['owner_id', 'status'], 'shared_sessions_owner_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('shared_sessions', fn (Blueprint $table) => $table->dropIndex('shared_sessions_owner_status_index'));
        Schema::table('hotspot_users', fn (Blueprint $table) => $table->dropIndex('hotspot_users_owner_created_index'));
        Schema::dropIfExists('admin_audit_logs');
    }
};
