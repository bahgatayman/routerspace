<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Simple demo/login accounts. Both use the password "password".
 * Idempotent — safe to run repeatedly (keyed on email).
 *
 * Runs after FeatureSeeder + PlanSeeder so the owner can be given a plan
 * and have every feature enabled.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        // Platform operator (admin guard). Admin has no "hashed" cast, so hash here.
        Admin::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        // Tenant (owner guard). Owner casts password => hashed, so pass it plain.
        $plan = Plan::where('slug', 'free')->first() ?? Plan::first();

        $owner = Owner::updateOrCreate(
            ['email' => 'owner@gmail.com'],
            [
                'name' => 'Demo Owner',
                'password' => 'password',
                'business_name' => 'Demo Space',
                'plan_id' => $plan?->id,
                'is_active' => true,
                'subscription_starts_at' => now(),
                'subscription_expires_at' => now()->addYear(),
            ]
        );

        // Give the demo owner the full panel to explore.
        foreach (['hotspot', 'workspace', 'booking', 'sales'] as $feature) {
            $owner->enableFeature($feature);
        }
    }
}
