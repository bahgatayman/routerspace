<?php

namespace Tests\Support;

use App\Exceptions\MikroTik\MikroTikAuthenticationException;
use App\Exceptions\MikroTik\MikroTikConnectionException;
use App\Exceptions\MikroTik\MikroTikOperationException;
use App\Services\MikroTikService;

/**
 * An in-memory stand-in for a RouterOS router — no socket, no real network
 * I/O. Used because no real MikroTik router is available to test against;
 * this exercises the exact call sequences/arguments MikroTikService's real
 * callers (HotspotSyncService, controllers) produce, and lets tests assert
 * on them directly instead of guessing at real-router behavior.
 *
 * Mirrors the real service's RouterOS semantics closely enough to catch
 * regressions in the calling code (e.g. the delete/update lookup-by-id
 * pattern, the profile-rename ordering), but is NOT a substitute for
 * verifying against a real router.
 */
class FakeMikroTikService extends MikroTikService
{
    /** @var array<int, array<int, mixed>> every method call, in order, as [methodName, ...args] */
    public array $calls = [];

    public bool $failConnect = false;

    public bool $failAuth = false;

    /** @var array<string, string> phone => profile name */
    public array $users = [];

    /** @var array<string, bool> phone => disabled */
    public array $disabled = [];

    /** When true, a mutating call still succeeds but the read-back verification methods report a DIFFERENT value than what was just set — simulates a router that silently rejected/ignored part of an update. */
    public bool $simulateVerificationMismatch = false;

    /** @var array<string, array{download: string, upload: string}> */
    public array $profiles = [];

    public function __construct()
    {
        // Deliberately skips the real constructor — no host/port/credentials needed.
    }

    public function connect(): bool
    {
        $this->calls[] = ['connect'];

        if ($this->failConnect) {
            throw new MikroTikConnectionException('fake: router unreachable');
        }
        if ($this->failAuth) {
            throw new MikroTikAuthenticationException('fake: invalid username or password');
        }

        return true;
    }

    public function disconnect(): void
    {
        $this->calls[] = ['disconnect'];
    }

    public function createHotspotUser(string $phone, string $password, string $profileName): bool
    {
        $this->calls[] = ['createHotspotUser', $phone, $password, $profileName];

        if (isset($this->users[$phone])) {
            throw new MikroTikOperationException('Failed to create hotspot user: already have such user');
        }

        $this->users[$phone] = $profileName;

        return true;
    }

    public function deleteHotspotUser(string $phone): bool
    {
        $this->calls[] = ['deleteHotspotUser', $phone];

        if (! isset($this->users[$phone])) {
            throw new MikroTikOperationException("User '$phone' not found on MikroTik");
        }

        unset($this->users[$phone]);

        return true;
    }

    public function setUserSpeed(string $phone, string $profileName): bool
    {
        $this->calls[] = ['setUserSpeed', $phone, $profileName];

        if (! isset($this->users[$phone])) {
            throw new MikroTikOperationException("User '$phone' not found on MikroTik");
        }

        $this->users[$phone] = $profileName;

        return true;
    }

    public function getActiveUsers(): array
    {
        $this->calls[] = ['getActiveUsers'];

        return [];
    }

    public function disableHotspotUser(string $phone): bool
    {
        $this->calls[] = ['disableHotspotUser', $phone];

        if (! isset($this->users[$phone])) {
            throw new MikroTikOperationException("User '$phone' not found on MikroTik");
        }

        $this->disabled[$phone] = true;

        return true;
    }

    public function enableHotspotUser(string $phone): bool
    {
        $this->calls[] = ['enableHotspotUser', $phone];

        if (! isset($this->users[$phone])) {
            throw new MikroTikOperationException("User '$phone' not found on MikroTik");
        }

        $this->disabled[$phone] = false;

        return true;
    }

    public function getHotspotUserDisabled(string $phone): ?bool
    {
        $this->calls[] = ['getHotspotUserDisabled', $phone];

        if (! isset($this->users[$phone])) {
            return null;
        }

        $actual = $this->disabled[$phone] ?? false;

        return $this->simulateVerificationMismatch ? ! $actual : $actual;
    }

    public function getHotspotUserProfile(string $phone): ?string
    {
        $this->calls[] = ['getHotspotUserProfile', $phone];

        if (! isset($this->users[$phone])) {
            return null;
        }

        return $this->simulateVerificationMismatch ? 'wrong-profile' : $this->users[$phone];
    }

    public function getHotspotProfileRateLimit(string $name): ?string
    {
        $this->calls[] = ['getHotspotProfileRateLimit', $name];

        if (! isset($this->profiles[$name])) {
            return null;
        }

        if ($this->simulateVerificationMismatch) {
            return 'wrong/rate-limit';
        }

        return $this->profiles[$name]['upload'].'/'.$this->profiles[$name]['download'];
    }

    public function createHotspotProfile(string $name, string $speedDownload, string $speedUpload): bool
    {
        $this->calls[] = ['createHotspotProfile', $name, $speedDownload, $speedUpload];

        if (isset($this->profiles[$name])) {
            throw new MikroTikOperationException('Failed to create hotspot profile: already have such profile');
        }

        $this->profiles[$name] = ['download' => $speedDownload, 'upload' => $speedUpload];

        return true;
    }

    public function updateHotspotProfile(string $currentName, string $speedDownload, string $speedUpload, ?string $newName = null): bool
    {
        $this->calls[] = ['updateHotspotProfile', $currentName, $speedDownload, $speedUpload, $newName];

        if (! isset($this->profiles[$currentName])) {
            throw new MikroTikOperationException("Profile '$currentName' not found on MikroTik");
        }

        unset($this->profiles[$currentName]);
        $finalName = $newName ?? $currentName;
        $this->profiles[$finalName] = ['download' => $speedDownload, 'upload' => $speedUpload];

        // A rename, mirrored on every user whose profile pointed at the old name — same as a real router,
        // where the profile object itself was renamed in place rather than replaced.
        if ($newName !== null && $newName !== $currentName) {
            foreach ($this->users as $phone => $profileName) {
                if ($profileName === $currentName) {
                    $this->users[$phone] = $newName;
                }
            }
        }

        return true;
    }

    public function deleteHotspotProfile(string $name): bool
    {
        $this->calls[] = ['deleteHotspotProfile', $name];

        if (! isset($this->profiles[$name])) {
            throw new MikroTikOperationException("Profile '$name' not found on MikroTik");
        }

        unset($this->profiles[$name]);

        return true;
    }
}
