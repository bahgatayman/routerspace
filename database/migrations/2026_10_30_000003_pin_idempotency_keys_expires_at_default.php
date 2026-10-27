<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * idempotency_keys.expires_at is a plain NOT NULL TIMESTAMP — the table's
 * first one. On a MySQL/MariaDB server running with
 * explicit_defaults_for_timestamp = OFF (MySQL 5.7, MariaDB ≤ 10.9 default)
 * such a column silently gets DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP. BookingController links the new booking to its claim
 * with an UPDATE, which then reset expires_at to "now": the key stopped
 * matching RequestIdempotency::find() (expires_at >= now) moments later, so a
 * retry was no longer recognised and could book twice.
 *
 * A forward migration (not an edit of create_idempotency_keys_table) so that
 * databases where that table already exists are repaired too. An explicit
 * DEFAULT stops the server adding ON UPDATE, whatever the setting; the
 * column stays NOT NULL and RequestIdempotency::claim() still sets every
 * expiry itself, so the default is never relied on. Existing rows keep
 * their values (at worst a key is already expired — the safe direction:
 * it is pruned within a day and never extends a guard).
 *
 * MySQL/MariaDB only: SQLite has no such behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE '.DB::getTablePrefix().'idempotency_keys MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // The original definition (the server applies its own defaults again).
        DB::statement('ALTER TABLE '.DB::getTablePrefix().'idempotency_keys MODIFY expires_at TIMESTAMP NOT NULL');
    }
};
