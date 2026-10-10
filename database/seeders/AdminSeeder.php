<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * First Super Admin account. Credentials never live in the repository:
 * set ADMIN_SEED_EMAIL / ADMIN_SEED_PASSWORD in .env, otherwise a random
 * password is generated and printed once. Re-running never changes an
 * existing admin's password.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL') ?: 'bahgatayman@gmail.com';
        $password = env('ADMIN_SEED_PASSWORD');
        $generated = blank($password);
        if ($generated) {
            $password = Str::password(20);
        }

        $admin = Admin::firstOrCreate(
            ['email' => $email],
            ['name' => 'Super Admin', 'password' => bcrypt($password)],
        );

        if ($admin->wasRecentlyCreated && $generated) {
            $this->command?->warn("Super Admin {$email} created with a generated password (shown once): {$password}");
        }
    }
}
