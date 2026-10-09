<?php

namespace App\Services;

use App\Exceptions\MikroTik\MikroTikAuthenticationException;
use App\Exceptions\MikroTik\MikroTikConnectionException;
use App\Exceptions\MikroTik\MikroTikVerificationException;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\RouterSyncTask;
use App\Models\SpeedProfile;
use Exception;

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
    /** Protected (not private) so tests can substitute a fake client without a real router — see tests/Support/. */
    protected function client(Owner $owner): MikroTikService
    {
        return new MikroTikService(
            $owner->mikrotik_host,
            $owner->mikrotik_port,
            $owner->mikrotik_username,
            $owner->mikrotik_password,
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
     * $priorName is the name the profile was saved under on the router BEFORE
     * this update — pass the name as it was before any DB rename, since the
     * router still has the profile filed under that name. $profile->name (the
     * possibly-new name) is what the router profile and every reassigned user
     * end up pointing at.
     *
     * @param  iterable<int, HotspotUser>  $assignedUsers
     * @return string[] per-user sync errors
     */
    public function syncProfileToUsers(Owner $owner, SpeedProfile $profile, iterable $assignedUsers, string $priorName): array
    {
        if (! $this->shouldSync($owner)) {
            return [];
        }

        $client = $this->client($owner);
        $errors = [];

        try {
            $client->connect();
            $client->updateHotspotProfile($priorName, $profile->speed_download, $profile->speed_upload, $profile->name);

            foreach ($assignedUsers as $user) {
                try {
                    $client->setUserSpeed($user->router_username ?? $user->phone, $profile->name);
                    $user->update([
                        'speed_download' => $profile->speed_download,
                        'speed_upload' => $profile->speed_upload,
                    ]);
                } catch (Exception $e) {
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
     * Records the result on the owner (for the Settings "last checked" display) and
     * throws on failure, same as before.
     */
    public function testConnection(Owner $owner): void
    {
        $client = $this->client($owner);
        try {
            $client->connect();
            $owner->update(['mikrotik_last_checked_at' => now(), 'mikrotik_last_check_status' => 'connected']);
        } catch (MikroTikAuthenticationException $e) {
            $owner->update(['mikrotik_last_checked_at' => now(), 'mikrotik_last_check_status' => 'auth_failed']);
            throw $e;
        } catch (MikroTikConnectionException $e) {
            $owner->update(['mikrotik_last_checked_at' => now(), 'mikrotik_last_check_status' => 'unreachable']);
            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Disable the router account without deleting it — the app's "suspend."
     * No-op (app state still updated by the caller) when sync is disabled.
     * Verifies the router actually reports the user as disabled afterward;
     * a connection failure leaves a 'pending' RouterSyncTask for retry, any
     * other failure (auth, operation, verification mismatch) leaves a
     * 'failed' one. Always throws on any failure so the caller can tell the
     * owner sync isn't confirmed yet — it never silently reports success.
     */
    public function suspendUser(Owner $owner, HotspotUser $user): void
    {
        $this->applyLifecycleChange($owner, $user, 'suspend');
    }

    /** Mirror of suspendUser() — re-enables the existing router account, same profile/password untouched. */
    public function reactivateUser(Owner $owner, HotspotUser $user): void
    {
        $this->applyLifecycleChange($owner, $user, 'reactivate');
    }

    private function applyLifecycleChange(Owner $owner, HotspotUser $user, string $type): void
    {
        // Any older task trying to reach the OPPOSITE state is moot now — the
        // owner's latest intent is what matters, never a stale queued flip.
        $this->resolveTasks($user, $type === 'suspend' ? 'reactivate' : 'suspend');

        if (! $this->shouldSync($owner)) {
            $user->update(['router_sync_status' => 'synced', 'router_synced_at' => now(), 'router_sync_error' => null]);

            return;
        }

        $username = $user->router_username ?? $user->phone;
        $client = $this->client($owner);

        try {
            $client->connect();
            $type === 'suspend' ? $client->disableHotspotUser($username) : $client->enableHotspotUser($username);

            $actualDisabled = $client->getHotspotUserDisabled($username);
            if ($actualDisabled !== ($type === 'suspend')) {
                throw new MikroTikVerificationException("Router did not confirm the $type — reports disabled=".var_export($actualDisabled, true));
            }

            $this->resolveTasks($user, $type);
            $user->update(['router_sync_status' => 'synced', 'router_synced_at' => now(), 'router_sync_error' => null]);
        } catch (MikroTikConnectionException $e) {
            $user->update(['router_sync_status' => 'pending', 'router_sync_error' => $e->getMessage()]);
            $this->upsertTask($owner, $user, $type, 'pending', $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            $user->update(['router_sync_status' => 'failed', 'router_sync_error' => $e->getMessage()]);
            $this->upsertTask($owner, $user, $type, 'failed', $e->getMessage());
            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Apply a speed-profile change and verify, by reading it back, that the
     * router actually agrees before touching the user's DB speed columns —
     * "do not report success when the actual configuration differs."
     *
     * On a connection failure, the requested profile is still recorded as
     * the user's intent (speed_profile_id) with router_sync_status left
     * 'pending' rather than silently claiming the old value still holds —
     * mirrors suspend/reactivate's "preserve the requested state" contract.
     * On any other failure (auth, operation rejection, or a verification
     * mismatch), the DB speed columns are left exactly as they were, since
     * at that point the router has had its say and it disagrees — a
     * RouterSyncTask('failed') is still recorded for visibility either way.
     */
    public function applyVerifiedSpeed(Owner $owner, HotspotUser $user, SpeedProfile $profile): void
    {
        if (! $this->shouldSync($owner)) {
            $user->update([
                'speed_download' => $profile->speed_download,
                'speed_upload' => $profile->speed_upload,
                'speed_profile_id' => $profile->id,
                'router_sync_status' => 'synced',
                'router_synced_at' => now(),
                'router_sync_error' => null,
            ]);
            $this->resolveTasks($user, 'speed_change');

            return;
        }

        $username = $user->router_username ?? $user->phone;
        $client = $this->client($owner);

        try {
            $client->connect();
            $client->setUserSpeed($username, $profile->name);

            $actualProfile = $client->getHotspotUserProfile($username);
            if ($actualProfile !== $profile->name) {
                throw new MikroTikVerificationException("Router reports profile '".($actualProfile ?? 'none')."', expected '{$profile->name}'.");
            }

            $user->update([
                'speed_download' => $profile->speed_download,
                'speed_upload' => $profile->speed_upload,
                'speed_profile_id' => $profile->id,
                'router_sync_status' => 'synced',
                'router_synced_at' => now(),
                'router_sync_error' => null,
            ]);
            $this->resolveTasks($user, 'speed_change');
        } catch (MikroTikConnectionException $e) {
            // Denormalized speed_download/speed_upload move WITH speed_profile_id here —
            // leaving them at the old profile's values while speed_profile_id already
            // points at the new one would make the two columns disagree with each other,
            // not just with the router.
            $user->update([
                'speed_download' => $profile->speed_download,
                'speed_upload' => $profile->speed_upload,
                'speed_profile_id' => $profile->id,
                'router_sync_status' => 'pending',
                'router_sync_error' => $e->getMessage(),
            ]);
            $this->upsertTask($owner, $user, 'speed_change', 'pending', $e->getMessage(), $profile->id);
            throw $e;
        } catch (Exception $e) {
            $user->update(['router_sync_status' => 'failed', 'router_sync_error' => $e->getMessage()]);
            $this->upsertTask($owner, $user, 'speed_change', 'failed', $e->getMessage(), $profile->id);
            throw $e;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Re-attempt a recorded task against the user's CURRENT desired state —
     * not whatever the task was created for. If the owner toggled status or
     * changed the target speed profile again since the task was queued, the
     * retry targets that latest intent, never a stale one. Returns false
     * (never throws) so a batch retry (the reconcile command, or a bulk
     * "retry all" action) can keep going through the rest of a list.
     */
    public function retryTask(RouterSyncTask $task): bool
    {
        $owner = $task->owner;
        $user = $task->hotspotUser;

        if (! $owner || ! $user) {
            $task->delete();

            return false;
        }

        try {
            match ($task->type) {
                'suspend', 'reactivate' => $this->applyLifecycleChange(
                    $owner, $user, $user->status === 'inactive' ? 'suspend' : 'reactivate'
                ),
                'speed_change' => $this->applyVerifiedSpeed(
                    $owner, $user, $user->speedProfile ?? $task->speedProfile
                ),
            };

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /** Upserts the one pending/failed task for this (user, type) pair rather than piling up duplicates on repeated failures. */
    private function upsertTask(Owner $owner, HotspotUser $user, string $type, string $status, string $error, ?int $speedProfileId = null): void
    {
        $task = RouterSyncTask::where('hotspot_user_id', $user->id)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'failed'])
            ->first() ?? new RouterSyncTask([
                'owner_id' => $owner->id,
                'hotspot_user_id' => $user->id,
                'type' => $type,
                'attempts' => 0,
            ]);

        $task->speed_profile_id = $speedProfileId;
        $task->status = $status;
        $task->last_error = $error;
        $task->last_attempted_at = now();
        $task->attempts = ($task->attempts ?? 0) + 1;
        $task->save();
    }

    private function resolveTasks(HotspotUser $user, string $type): void
    {
        RouterSyncTask::where('hotspot_user_id', $user->id)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'failed'])
            ->delete();
    }
}
