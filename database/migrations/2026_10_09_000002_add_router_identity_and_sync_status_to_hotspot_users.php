<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two separate concerns bolted onto hotspot_users:
 *
 * 1. Phone identity — `phone` stays exactly what it always was (the
 *    display value, and every EXISTING row's actual RouterOS username).
 *    `phone_normalized` is the new canonical form (see App\Support\PhoneNumber)
 *    used only for cross-format duplicate detection going forward.
 *    `router_username` is the one true RouterOS identity: backfilled to the
 *    existing `phone` value for every current row (so nothing already
 *    provisioned changes), and set to the canonical form for new rows going
 *    forward (see HotspotUserController).
 *
 * 2. Router sync visibility — `router_sync_status`/`router_synced_at`/
 *    `router_sync_error` track whether the app's intent (active/inactive,
 *    assigned speed profile) actually matches what's on the router, for the
 *    owner-facing status display and the retry/reconciliation flow.
 *
 * Existing rows: router_username = phone (exact copy, preserves every
 * already-provisioned account's real username); phone_normalized is set on
 * a best-effort basis and left null on anything that doesn't parse as an
 * Egyptian mobile number OR that would collide with another row for the
 * same owner (collisions are NOT auto-merged — flagged via a log warning
 * for manual review instead, per "do not merge accounts automatically").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->string('phone_normalized')->nullable()->after('phone');
            $table->string('router_username')->nullable()->after('phone_normalized');
            $table->enum('router_sync_status', ['synced', 'pending', 'failed'])->default('synced')->after('status');
            $table->timestamp('router_synced_at')->nullable()->after('router_sync_status');
            $table->text('router_sync_error')->nullable()->after('router_synced_at');
        });

        // router_username: always the existing phone, for every current row — no exceptions.
        DB::table('hotspot_users')->update(['router_username' => DB::raw('phone')]);

        // phone_normalized: best-effort, skipping anything that would collide.
        $seenPerOwner = []; // owner_id => [normalized => hotspot_user_id]
        DB::table('hotspot_users')->orderBy('id')->select('id', 'owner_id', 'phone')->get()
            ->each(function ($row) use (&$seenPerOwner) {
                $normalized = PhoneNumber::normalize($row->phone);
                if ($normalized === null) {
                    return;
                }

                $existing = $seenPerOwner[$row->owner_id][$normalized] ?? null;
                if ($existing !== null) {
                    logger()->warning('hotspot_users phone_normalized backfill: collision left unresolved', [
                        'owner_id' => $row->owner_id,
                        'normalized' => $normalized,
                        'hotspot_user_ids' => [$existing, $row->id],
                    ]);

                    return;
                }

                $seenPerOwner[$row->owner_id][$normalized] = $row->id;
                DB::table('hotspot_users')->where('id', $row->id)->update(['phone_normalized' => $normalized]);
            });

        // Added after backfill so any (already-skipped) collision never hits this constraint;
        // NULLs are never considered duplicates of each other under this index.
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->unique(['owner_id', 'phone_normalized']);
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_users', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'phone_normalized']);
            $table->dropColumn(['phone_normalized', 'router_username', 'router_sync_status', 'router_synced_at', 'router_sync_error']);
        });
    }
};
