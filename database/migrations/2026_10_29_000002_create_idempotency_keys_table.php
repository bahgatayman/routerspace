<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent once-only keys for money/booking writes (App\Support\RequestIdempotency).
 * The row is inserted in the same transaction as the write it protects, so it
 * exists only if that write committed: a failed attempt leaves no row behind
 * and its key can be retried. The unique index is what stops two concurrent
 * copies of one request — plain columns only, same behaviour on MySQL/MariaDB
 * and SQLite. Rows expire after 24 hours and are pruned daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('scope', 32);
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['owner_id', 'actor_type', 'actor_id', 'scope', 'key'], 'idempotency_keys_unique');
            $table->index('expires_at');
        });
    }

    /** Only once-only markers live here (no business data), so dropping it is safe. */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
