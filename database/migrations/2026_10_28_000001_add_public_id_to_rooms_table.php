<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stable public identifier for rooms (used by the customer API instead of the
 * internal primary key). Random UUIDv4 strings generated in PHP — no
 * database-specific UUID default — so the same migration works on MySQL/
 * MariaDB and SQLite.
 *
 * Steps, each safe to re-run if a previous attempt stopped half-way (MySQL
 * DDL isn't transactional, so a run can die after any statement):
 *   1. add a nullable column (if missing),
 *   2. backfill rows that have no value yet — existing values are never
 *      replaced, so identifiers issued by an earlier attempt survive,
 *   3. refuse (without changing anything) if two rooms share a value,
 *   4. enforce NOT NULL (if still nullable),
 *   5. add the unique index (if no unique index on public_id exists yet).
 * State is read through Laravel's schema introspection (getColumns /
 * getIndexes), which is implemented for SQLite and MySQL/MariaDB alike.
 * Only rooms.public_id is touched — ownership, pricing, bookings and sessions
 * are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rooms', 'public_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->string('public_id', 36)->nullable()->after('id');
            });
        }

        $missing = fn ($query) => $query->whereNull('public_id')->orWhere('public_id', '');
        DB::table('rooms')->where($missing)->orderBy('id')->select('id')
            ->chunkById(500, function ($rooms) use ($missing) {
                foreach ($rooms as $room) {
                    DB::table('rooms')->where('id', $room->id)->where($missing)
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });

        // Never "fix" duplicates by regenerating: either value may already be in use publicly.
        $duplicates = DB::table('rooms')->select('public_id')->groupBy('public_id')
            ->havingRaw('COUNT(*) > 1')->limit(5)->pluck('public_id');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('rooms.public_id has duplicate values ('.$duplicates->implode(', ').'). Resolve them manually, keeping the identifier clients already use, then re-run the migration.');
        }

        $column = collect(Schema::getColumns('rooms'))->firstWhere('name', 'public_id');
        if ($column['nullable']) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->string('public_id', 36)->nullable(false)->change();
            });
        }

        if (! self::hasUniqueIndex()) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->unique('public_id');
            });
        }
    }

    /** Any unique index covering exactly public_id counts, whatever it was named. */
    private static function hasUniqueIndex(): bool
    {
        return collect(Schema::getIndexes('rooms'))
            ->contains(fn (array $index) => $index['unique'] && $index['columns'] === ['public_id']);
    }

    /**
     * Dropping the column discards identifiers that public clients (website,
     * app, saved links) may already reference; regenerated ones would differ.
     * Refuse unless explicitly confirmed with ALLOW_DROP_ROOM_PUBLIC_IDS=1 in
     * the process environment (or there are no rooms at all).
     */
    public function down(): void
    {
        $rooms = DB::table('rooms')->count();
        if ($rooms > 0 && getenv('ALLOW_DROP_ROOM_PUBLIC_IDS') !== '1') {
            throw new RuntimeException("Refusing to drop rooms.public_id for {$rooms} room(s): public clients may reference these identifiers. Re-run with ALLOW_DROP_ROOM_PUBLIC_IDS=1 to confirm.");
        }

        if (! Schema::hasColumn('rooms', 'public_id')) {
            return;
        }

        // Drop whichever unique index exists on the column (a recovered install may have named it differently).
        $indexes = collect(Schema::getIndexes('rooms'))
            ->filter(fn (array $index) => ! $index['primary'] && $index['columns'] === ['public_id'])
            ->pluck('name');

        Schema::table('rooms', function (Blueprint $table) use ($indexes) {
            foreach ($indexes as $name) {
                $table->dropIndex($name);
            }
            $table->dropColumn('public_id');
        });
    }
};
