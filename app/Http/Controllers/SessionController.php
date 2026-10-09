<?php

namespace App\Http\Controllers;

use App\Models\HotspotUser;
use App\Services\HotspotSyncService;
use App\Support\TenantContext;
use Exception;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function __construct(private HotspotSyncService $sync) {}

    public function index(): Response
    {
        $owner = TenantContext::user();
        $sessions = [];
        $error = null;

        try {
            $rawSessions = $this->sync->activeUsers($owner);

            $phones = collect($rawSessions)->pluck('phone')->toArray();
            $localUsers = HotspotUser::where('owner_id', $owner->id)
                ->whereIn('phone', $phones)
                ->get()
                ->keyBy('phone');

            $sessions = collect($rawSessions)->map(function ($session) use ($localUsers) {
                $local = $localUsers->get($session['phone'] ?? $session['username'] ?? null);

                return array_merge($session, [
                    'name' => $local?->name ?? 'Unknown',
                    'user_id' => $local?->id ?? null,
                ]);
            })->toArray();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('Sessions/Index', [
            'sessions' => collect($sessions)->map(fn ($s) => [
                'name' => $s['name'],
                'login' => $s['phone'] ?? $s['username'] ?? null,
                'ip' => $s['ip'] ?? null,
                'uptime' => $s['uptime'] ?? null,
                'downloaded' => ! empty($s['bytes_in']) ? number_format($s['bytes_in'] / 1048576, 2).' MB' : null,
                'uploaded' => ! empty($s['bytes_out']) ? number_format($s['bytes_out'] / 1048576, 2).' MB' : null,
                'user_id' => $s['user_id'],
            ])->values(),
            'error' => $error,
            'updatedAt' => now()->format('H:i:s'),
        ]);
    }
}
