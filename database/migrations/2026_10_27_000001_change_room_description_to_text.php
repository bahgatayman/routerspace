<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rooms.description was created as VARCHAR(255) (and kept that way by the
 * 2026_07_01_000003 SQLite rebuild) while the room create/edit forms validate
 * up to 1000 characters. Widen to TEXT — lossless, existing values unchanged.
 * Forward-only: no earlier migration is edited. Only this column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    /** Refuse instead of truncating (or failing mid-way on strict MySQL). */
    public function down(): void
    {
        $tooLong = DB::table('rooms')->whereRaw('LENGTH(description) > 255')->count();
        if ($tooLong > 0) {
            throw new RuntimeException("Cannot roll back: {$tooLong} room description(s) are longer than 255 characters. Shorten them first.");
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
    }
};
