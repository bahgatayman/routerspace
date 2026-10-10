<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL', 'bahgatayman@gmail.com');

        // The repo is public: the simple "password" default is for local/dev only.
        // Production must set ADMIN_SEED_PASSWORD in .env, otherwise nothing is seeded.
        $password = env('ADMIN_SEED_PASSWORD') ?: (app()->isProduction() ? null : 'password');

        if ($password === null) {
            $this->command?->warn('AdminSeeder skipped: set ADMIN_SEED_PASSWORD in .env to seed the Super Admin in production.');

            return;
        }

        Admin::updateOrCreate(['email' => $email], [
            'name' => 'Super Admin',
            'password' => bcrypt($password),
        ]);
    }
}
