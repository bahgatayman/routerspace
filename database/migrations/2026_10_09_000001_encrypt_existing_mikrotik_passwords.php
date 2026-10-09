<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * owners.mikrotik_password was stored in plaintext (no cast, no encryption)
 * — a real credential-exposure gap, since it's a live RouterOS password.
 * The Owner model now casts this column 'encrypted', so Eloquent will
 * encrypt new writes automatically; this migration brings EXISTING rows up
 * to that same format in place (no data loss, no column/shape change).
 *
 * Idempotent: a value that already decrypts successfully (already run, or
 * a fresh encrypted value) is left untouched. Bypasses Eloquent/the new
 * cast entirely via the query builder, since this must work correctly
 * whether it runs before or after the cast is live.
 *
 * Before running this on any environment you haven't already migrated,
 * take a database backup first (this transforms a live secret column —
 * low risk given the idempotency/rollback above, but a secret is exactly
 * the kind of column worth having a safety net for). Run
 * `php artisan mikrotik:check-password-encryption` before AND after to
 * confirm what's about to change and that every row stayed readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owners')
            ->whereNotNull('mikrotik_password')
            ->where('mikrotik_password', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($owners) {
                foreach ($owners as $owner) {
                    try {
                        Crypt::decryptString($owner->mikrotik_password);

                        continue; // already encrypted
                    } catch (Throwable) {
                        // plaintext — fall through and encrypt it below
                    }

                    DB::table('owners')->where('id', $owner->id)->update([
                        'mikrotik_password' => Crypt::encryptString($owner->mikrotik_password),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('owners')
            ->whereNotNull('mikrotik_password')
            ->where('mikrotik_password', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($owners) {
                foreach ($owners as $owner) {
                    try {
                        $plain = Crypt::decryptString($owner->mikrotik_password);
                    } catch (Throwable) {
                        continue; // already plaintext (or genuinely undecryptable) — leave as-is
                    }

                    DB::table('owners')->where('id', $owner->id)->update([
                        'mikrotik_password' => $plain,
                    ]);
                }
            });
    }
};
