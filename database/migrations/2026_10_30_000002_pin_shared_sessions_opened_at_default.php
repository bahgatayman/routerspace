<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * shared_sessions.opened_at was created as a plain NOT NULL TIMESTAMP — the
 * table's first one. On a MySQL/MariaDB server running with
 * explicit_defaults_for_timestamp = OFF (MySQL 5.7, MariaDB ≤ 10.9 default)
 * such a column silently gets DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP, so closing a session (an UPDATE) reset its start to
 * "now" and billed 0 minutes. An explicit DEFAULT stops the server adding
 * ON UPDATE, whatever the setting. Every write already sets opened_at, so the
 * default itself is never relied on. Existing values are kept as they are.
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

        DB::statement('ALTER TABLE '.DB::getTablePrefix().'shared_sessions MODIFY opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // The original definition (the server applies its own defaults again).
        DB::statement('ALTER TABLE '.DB::getTablePrefix().'shared_sessions MODIFY opened_at TIMESTAMP NOT NULL');
    }
};
