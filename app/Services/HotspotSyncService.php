<?php

namespace App\Services;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\SpeedProfile;

/**
 * Feature-aware boundary between the app and the MikroTik router.
 *
 * Every router interaction goes through here so that (a) building a client from
 * the owner's credentials lives in one place and (b) the hotspot feature gate is
 * centralized. Method signatures deliberately do NOT expose MikroTikService, so a
 * second router vendor could later sit behind an extracted interface without
 * touching callers.
 *
 * Gating policy (`shouldSync`): router work only runs when the owner has the
 * `hotspot` feature AND has configured credentials. When it doesn't:
 *   - mutating ops (create/delete/setSpeed/profile sync) are a silent no-op — a
 *     booking-only owner manages members with no router side-effects, and a
 *     hotspot owner mid-setup isn't hard-blocked (callers surface the "configure
 *     your router" nudge for that case);
 *   - reads (`activeUsers`) return empty so no socket is ever opened.
 * `testConnection` is exempt — it is an explicit connectivity check and must
 * actually attempt to connect.
 */
class HotspotSyncService
{
    private function client(Owner $owner): MikroTikService
    {
        // The settings/register forms allow an empty router password, and a
        // model built in memory may lack the port default: pass strings and
        // the standard RouterOS API port rather than nulls, so a half-filled
        // router config fails to connect (handled by callers) instead of
        // throwing a TypeError (dashboard 500).
        return new MikroTikService(
            (string) $owner->mikrotik_host,
            (int) ($owner->mikrotik_port ?: 8728),
            (string) $owner->mikrotik_username,
            (string) $owner->mikrotik_password,
        );
    }

    /** Router work runs only for a hotspot-enabled, configured owner. */
    private function shouldSync(Owner $owner): bool
    {
        return $owner->hasFeature('hotspot') && $owner->hasRouterConfigured();
    }

    /** Provision a hotspot user on the router. No-op when sync is disabled. Throws on router failure. */
    public function createUser(Owner $owner, string $phone, string $password, string $profileName): void
    {
        if (! $this->shouldSync($owner)) {
            return;
        }

        $client = $this->client($owner);
        try {
            $client->connect();
            $client->createHotspotUser($phone, $password, $profileName);
        } finally {
            $client->disconnect();
        }
    }

    /** Remove a hotspot user from the router. No-op when sync is disabled. Throws on router failure. */
    public function deleteUser(Owner $owner, string $phone): void
    {
        if (! $this->shouldSync($owner)) {
            return;
        }

        $client = $this->client($owner);
        try {
            $client->connect();
            $client->deleteHotspotUser($phone);
        } finally {
            $client->disconnect();
        }
    }

    /** Re-point a single user at a profile on the router. No-op when sync is disabled. Throws on router failure. */
    public function setUserSpeed(Owner $owner, string $phone, string $profileName): void
    {
        if (! $this->shouldSync($owner)) {
            return;
        }

        $client = $this->client($owner);
        try {
            $client->connect();
            $client->setUserSpeed($phone, $profileName);
        } finally {
            $client->disconnect();
        }
    }

    /** Create a hotspot profile on the router. No-op when sync is disabled. Throws on router failure. */
    public function createProfile(Owner $owner, string $name, string $speedDownload, string $speedUpload): void
    {
        if (! $this->shouldSync($owner)) {
            return;
        }

        $client = $this->client($owner);
        try {
            $client->connect();
            $client->createHotspotProfile($name, $speedDownload, $speedUpload);
        } finally {
            $client->disconnect();
        }
    }

    /** Delete a hotspot profile from the router. No-op when sync is disabled. Throws on router failure. */
    public function deleteProfile(Owner $owner, string $name): void
    {
        if (! $this->shouldSync($owner)) {
            return;
        }

        $client = $this->client($owner);
        try {
            $client->connect();
            $client->deleteHotspotProfile($name);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Update a profile on the router and re-apply it to each assigned user in a
     * single connection. Best-effort per user: returns "name: error" strings for
     * users that failed (empty array = all synced, or sync disabled). Throws only
     * if the connect / profile-update step itself fails.
     *
     * @param  iterable<int, HotspotUser>  $assignedUsers
     * @return string[] per-user sync errors
     */
    public function syncProfileToUsers(Owner $owner, SpeedProfile $profile, iterable $assignedUsers): array
    {
        if (! $this->shouldSync($owner)) {
            return [];
        }

        $client = $this->client($owner);
        $errors = [];

        try {
            $client->connect();
            $client->updateHotspotProfile($profile->name, $profile->speed_download, $profile->speed_upload);

            foreach ($assignedUsers as $user) {
                try {
                    $client->setUserSpeed($user->phone, $profile->name);
                    $user->update([
                        'speed_download' => $profile->speed_download,
                        'speed_upload' => $profile->speed_upload,
                    ]);
                } catch (\Exception $e) {
                    $errors[] = $user->name.': '.$e->getMessage();
                }
            }
        } finally {
            $client->disconnect();
        }

        return $errors;
    }

    /** Live active hotspot users from the router. Empty (no socket opened) when sync is disabled. Throws on router failure. */
    public function activeUsers(Owner $owner): array
    {
        if (! $this->shouldSync($owner)) {
            return [];
        }

        $client = $this->client($owner);
        try {
            $client->connect();

            return $client->getActiveUsers();
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Verify the owner's router credentials connect. Deliberately NOT gated: it is
     * an explicit connectivity check invoked from the (hotspot-gated) Settings page.
     * Throws on failure.
     */
    public function testConnection(Owner $owner): void
    {
        $client = $this->client($owner);
        try {
            $client->connect();
        } finally {
            $client->disconnect();
        }
    }
}
