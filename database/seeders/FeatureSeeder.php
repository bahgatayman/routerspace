<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'key'         => 'hotspot',
                'name'        => 'MikroTik Hotspot Management',
                'description' => 'Manage internet users, speeds, and active sessions via MikroTik RouterOS',
                'icon'        => 'wifi',
                'is_active'   => true,
            ],
            [
                'key'         => 'workspace',
                'name'        => 'Workspace & Rooms',
                'description' => 'Add and manage workspaces and rooms in your coworking space',
                'icon'        => 'building',
                'is_active'   => true,
            ],
            [
                'key'         => 'booking',
                'name'        => 'Booking System',
                'description' => 'Allow customers to book workspaces and rooms',
                'icon'        => 'calendar',
                'is_active'   => true,
            ],
        ];

        foreach ($features as $feature) {
            Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }
}
