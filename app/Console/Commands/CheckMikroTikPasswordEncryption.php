<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Read-only safety check for the 2026_10_09_000001_encrypt_existing_mikrotik_passwords
 * migration — run this BEFORE migrating on a new environment (staging, a
 * second server, production) to know what you're about to change, and run
 * it again AFTER to confirm every row is actually readable through the
 * Owner model's 'encrypted' cast. Never prints an actual password value,
 * encrypted or not.
 */
class CheckMikroTikPasswordEncryption extends Command
{
    protected $signature = 'mikrotik:check-password-encryption';

    protected $description = 'Report how many owners.mikrotik_password values are plaintext vs. already encrypted, without exposing any value';

    public function handle(): int
    {
        $rows = DB::table('owners')
            ->whereNotNull('mikrotik_password')
            ->where('mikrotik_password', '!=', '')
            ->get(['id', 'mikrotik_password']);

        $encrypted = 0;
        $plaintext = 0;
        $plaintextOwnerIds = [];

        foreach ($rows as $row) {
            try {
                Crypt::decryptString($row->mikrotik_password);
                $encrypted++;
            } catch (\Throwable) {
                $plaintext++;
                $plaintextOwnerIds[] = $row->id;
            }
        }

        $this->table(
            ['Total owners with a password set', 'Already encrypted', 'Still plaintext'],
            [[$rows->count(), $encrypted, $plaintext]],
        );

        if ($plaintext > 0) {
            $this->warn('Owner IDs with a plaintext password (safe to log — no values shown): '.implode(', ', $plaintextOwnerIds));
            $this->warn('Back up the database, then run: php artisan migrate (applies 2026_10_09_000001_...) to encrypt these in place.');
        } else {
            $this->info('Nothing plaintext — either already migrated, or no routers configured yet.');
        }

        return self::SUCCESS;
    }
}
