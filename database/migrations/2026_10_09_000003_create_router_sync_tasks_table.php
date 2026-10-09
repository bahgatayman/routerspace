<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable record of a router mutation the app couldn't immediately confirm
 * — created when a MikroTik call fails, so the attempt is never just a
 * silently-dropped flash message. Retried either by the owner (a visible
 * "Retry" action) or by the `mikrotik:reconcile` command (see its own
 * docblock for why that's a plain synchronous command, not a queued job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->foreignId('hotspot_user_id')->nullable()->constrained('hotspot_users')->cascadeOnDelete();
            $table->foreignId('speed_profile_id')->nullable()->constrained('speed_profiles')->nullOnDelete();
            $table->enum('type', ['suspend', 'reactivate', 'speed_change']);
            $table->enum('status', ['pending', 'failed', 'success'])->default('pending');
            $table->json('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_sync_tasks');
    }
};
