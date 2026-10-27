<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple bookable uses of one physical room ("Shared Workspace", "Training
 * Room", "Studio"…). Each use is an ordinary room row — its own name, type,
 * capacity, pricing, plans, profiles, photos and public id — linked to the
 * room that represents the physical space by `physical_room_id`. A room with
 * a null value is a physical room (every existing room stays exactly that).
 *
 * Availability is shared across the physical room and all its uses
 * (AvailabilityService), and booking writes lock the physical room's row so
 * two uses can't be booked for overlapping times concurrently.
 *
 * Additive and safe on a live MySQL table: one nullable column (no default
 * rewrite, no backfill) plus an index and a foreign key. ON DELETE RESTRICT,
 * never CASCADE: a physical room can't be deleted from under its uses, and
 * nothing here can remove a booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rooms', 'physical_room_id')) {
            return; // re-run after a partial MySQL run (DDL isn't transactional)
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('physical_room_id')->nullable()->after('workspace_id')
                ->constrained('rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rooms', 'physical_room_id')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('physical_room_id');
        });
    }
};
