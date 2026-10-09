<?php

namespace App\Services;

use App\Exceptions\MikroTik\MikroTikAuthenticationException;
use App\Exceptions\MikroTik\MikroTikConnectionException;
use App\Exceptions\MikroTik\MikroTikOperationException;
use Exception;

class MikroTikService
{
    private $socket;

    private string $host;

    private int $port;

    private string $username;

    private string $password;

    public function __construct(string $host, int $port, string $username, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function connect(): bool
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);

        if (! $this->socket) {
            throw new MikroTikConnectionException("Connection failed: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 5);

        try {
            $this->sendSentence(['/login', "=name=$this->username", "=password=$this->password"]);
            $response = $this->readSentence();

            if (isset($response[0]) && $response[0] === '!done') {
                return true;
            }

            if (isset($response[0]) && $response[0] === '!trap') {
                $message = $response[1] ?? 'Unknown error';
                throw new MikroTikAuthenticationException("Login failed: $message");
            }

            $challenge = null;
            foreach ($response as $word) {
                if (str_starts_with($word, '=ret=')) {
                    $challenge = substr($word, 5);
                    break;
                }
            }

            if ($challenge) {
                $md5Challenge = pack('H*', $challenge);
                $md5Password = md5(chr(0).$this->password.$md5Challenge);
                $this->sendSentence(['/login', "=name=$this->username", '=response=00'.$md5Password]);
                $response = $this->readSentence();

                if (isset($response[0]) && $response[0] === '!done') {
                    return true;
                }

                if (isset($response[0]) && $response[0] === '!trap') {
                    $message = $response[1] ?? 'Unknown error';
                    throw new MikroTikAuthenticationException("Login failed: $message");
                }
            }

            throw new MikroTikConnectionException('Unexpected login response');
        } catch (Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function createHotspotUser(string $phone, string $password, string $profileName): bool
    {
        $this->sendSentence([
            '/ip/hotspot/user/add',
            '=name='.$phone,
            '=password='.$password,
            '=profile='.$profileName,
        ]);
        $response = $this->readSentence();
        if (isset($response[0]) && $response[0] === '!trap') {
            $message = $response[1] ?? 'Unknown error';
            throw new MikroTikOperationException("Failed to create hotspot user: $message");
        }

        return in_array('!done', $response);
    }

    public function deleteHotspotUser(string $phone): bool
    {
        $id = $this->findHotspotUserId($phone);

        $this->sendSentence(['/ip/hotspot/user/remove', '=.id='.$id]);
        $response = $this->readSentence();
        if (isset($response[0]) && $response[0] === '!trap') {
            $message = $response[1] ?? 'Unknown error';
            throw new MikroTikOperationException("Failed to delete hotspot user: $message");
        }

        return in_array('!done', $response);
    }

    public function setUserSpeed(string $phone, string $profileName): bool
    {
        $id = $this->findHotspotUserId($phone);

        $this->sendSentence([
            '/ip/hotspot/user/set',
            '=.id='.$id,
            '=profile='.$profileName,
        ]);
        $response = $this->readSentence();

        return in_array('!done', $response);
    }

    /** Disable (not delete) a hotspot user — their account/profile/password stay on the router, just unable to log in. */
    public function disableHotspotUser(string $phone): bool
    {
        $id = $this->findHotspotUserId($phone);

        $this->sendSentence(['/ip/hotspot/user/set', '=.id='.$id, '=disabled=yes']);
        $response = $this->readSentence();
        if (isset($response[0]) && $response[0] === '!trap') {
            $message = $response[1] ?? 'Unknown error';
            throw new MikroTikOperationException("Failed to disable hotspot user: $message");
        }

        return in_array('!done', $response);
    }

    /** Re-enable a previously disabled hotspot user — their existing profile/password are untouched. */
    public function enableHotspotUser(string $phone): bool
    {
        $id = $this->findHotspotUserId($phone);

        $this->sendSentence(['/ip/hotspot/user/set', '=.id='.$id, '=disabled=no']);
        $response = $this->readSentence();
        if (isset($response[0]) && $response[0] === '!trap') {
            $message = $response[1] ?? 'Unknown error';
            throw new MikroTikOperationException("Failed to enable hotspot user: $message");
        }

        return in_array('!done', $response);
    }

    /**
     * Read-back verification: the router's own idea of whether this user is
     * currently disabled. Null if the user isn't found at all (distinct from
     * false, which is a confirmed "not disabled" answer).
     */
    public function getHotspotUserDisabled(string $phone): ?bool
    {
        $this->sendSentence(['/ip/hotspot/user/print', '?name='.$phone]);
        $response = $this->readSentence();

        $found = false;
        $disabled = false;
        foreach ($response as $word) {
            if (str_starts_with($word, '=.id=')) {
                $found = true;
            }
            if (str_starts_with($word, '=disabled=')) {
                $disabled = substr($word, 10) === 'true';
            }
        }

        return $found ? $disabled : null;
    }

    /** Read-back verification: the profile currently assigned to this user on the router, or null if not found. */
    public function getHotspotUserProfile(string $phone): ?string
    {
        $this->sendSentence(['/ip/hotspot/user/print', '?name='.$phone]);
        $response = $this->readSentence();

        foreach ($response as $word) {
            if (str_starts_with($word, '=profile=')) {
                return substr($word, 9);
            }
        }

        return null;
    }

    /** Read-back verification: a profile's current rate-limit string ("upload/download"), or null if not found. */
    public function getHotspotProfileRateLimit(string $name): ?string
    {
        $this->sendSentence(['/ip/hotspot/user/profile/print', '?name='.$name]);
        $response = $this->readSentence();

        foreach ($response as $word) {
            if (str_starts_with($word, '=rate-limit=')) {
                return substr($word, 12);
            }
        }

        return null;
    }

    /** Shared by every method that mutates a specific hotspot user by name — resolves RouterOS's real numeric .id first. */
    private function findHotspotUserId(string $phone): string
    {
        $this->sendSentence(['/ip/hotspot/user/print', '?name='.$phone]);
        $response = $this->readSentence();
        $id = null;
        foreach ($response as $word) {
            if (str_starts_with($word, '=.id=')) {
                $id = substr($word, 5);
            }
        }
        if (! $id) {
            throw new MikroTikOperationException("User '$phone' not found on MikroTik");
        }

        return $id;
    }

    public function getActiveUsers(): array
    {
        $this->sendSentence(['/ip/hotspot/active/print']);
        $users = [];

        while (true) {
            $response = $this->readSentence();

            if (isset($response[0]) && $response[0] === '!done') {
                break;
            }

            if (isset($response[0]) && $response[0] === '!re') {
                $user = [];

                foreach ($response as $word) {
                    if (str_starts_with($word, '=user=')) {
                        $user['phone'] = substr($word, 6);
                    } elseif (str_starts_with($word, '=uptime=')) {
                        $user['uptime'] = substr($word, 8);
                    } elseif (str_starts_with($word, '=bytes-in=')) {
                        $user['bytes_in'] = substr($word, 10);
                    } elseif (str_starts_with($word, '=bytes-out=')) {
                        $user['bytes_out'] = substr($word, 11);
                    } elseif (str_starts_with($word, '=address=')) {
                        $user['ip'] = substr($word, 9);
                    }
                }

                if (! empty($user)) {
                    $users[] = $user;
                }
            }
        }

        return $users;
    }

    public function createHotspotProfile(string $name, string $speedDownload, string $speedUpload): bool
    {
        $rateLimit = $speedUpload.'/'.$speedDownload;
        $this->sendSentence([
            '/ip/hotspot/user/profile/add',
            '=name='.$name,
            '=rate-limit='.$rateLimit,
        ]);
        $response = $this->readSentence();
        if (isset($response[0]) && $response[0] === '!trap') {
            $message = $response[1] ?? 'Unknown error';
            throw new MikroTikOperationException("Failed to create hotspot profile: $message");
        }

        return in_array('!done', $response);
    }

    /**
     * $currentName is the name the profile is found under on the router right
     * now; $newName (when different) renames it in the same /set call. The
     * caller must pass the pre-rename name here — looking the profile up by
     * an already-renamed DB value would never find it on the router.
     */
    public function updateHotspotProfile(string $currentName, string $speedDownload, string $speedUpload, ?string $newName = null): bool
    {
        $rateLimit = $speedUpload.'/'.$speedDownload;

        $this->sendSentence([
            '/ip/hotspot/user/profile/print',
            '?name='.$currentName,
        ]);
        $response = $this->readSentence();
        $id = null;
        foreach ($response as $word) {
            if (str_starts_with($word, '=.id=')) {
                $id = substr($word, 5);
            }
        }
        if (! $id) {
            throw new MikroTikOperationException("Profile '$currentName' not found on MikroTik");
        }

        $words = [
            '/ip/hotspot/user/profile/set',
            '=.id='.$id,
            '=rate-limit='.$rateLimit,
        ];
        if ($newName !== null && $newName !== $currentName) {
            $words[] = '=name='.$newName;
        }

        $this->sendSentence($words);
        $response = $this->readSentence();

        return in_array('!done', $response);
    }

    public function deleteHotspotProfile(string $name): bool
    {
        $this->sendSentence([
            '/ip/hotspot/user/profile/print',
            '?name='.$name,
        ]);
        $response = $this->readSentence();
        $id = null;
        foreach ($response as $word) {
            if (str_starts_with($word, '=.id=')) {
                $id = substr($word, 5);
            }
        }
        if (! $id) {
            throw new MikroTikOperationException("Profile '$name' not found on MikroTik");
        }

        $this->sendSentence([
            '/ip/hotspot/user/profile/remove',
            '=.id='.$id,
        ]);
        $response = $this->readSentence();

        return in_array('!done', $response);
    }

    private function sendSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }

        $this->writeWord('');
    }

    private function readSentence(): array
    {
        $words = [];

        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                break;
            }

            $words[] = $word;
        }

        return $words;
    }

    private function writeWord(string $word): void
    {
        $length = strlen($word);
        $encoded = $this->encodeLength($length).$word;

        $written = @fwrite($this->socket, $encoded, strlen($encoded));

        if ($written === false) {
            throw new MikroTikConnectionException('Failed to write to socket');
        }
    }

    private function readWord(): string
    {
        $length = $this->readLength();

        if ($length === 0) {
            return '';
        }

        $word = @fread($this->socket, $length);

        if ($word === false) {
            throw new MikroTikConnectionException('Failed to read from socket');
        }

        return $word;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80).chr($length & 0xFF);
        }

        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        return chr(0xF0).chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
    }

    private function readLength(): int
    {
        $byte = @fread($this->socket, 1);

        if ($byte === false || $byte === '') {
            throw new MikroTikConnectionException('Failed to read length byte');
        }

        $ord = ord($byte);

        if ($ord < 0x80) {
            return $ord;
        }

        if ($ord < 0xC0) {
            $next = @fread($this->socket, 1);
            if ($next === false) {
                throw new MikroTikConnectionException('Failed to read second length byte');
            }

            return (($ord & 0x3F) << 8) | ord($next);
        }

        if ($ord < 0xE0) {
            $next = @fread($this->socket, 2);
            if ($next === false || strlen($next) < 2) {
                throw new MikroTikConnectionException('Failed to read length bytes');
            }

            return (($ord & 0x1F) << 16) | (ord($next[0]) << 8) | ord($next[1]);
        }

        if ($ord < 0xF0) {
            $next = @fread($this->socket, 3);
            if ($next === false || strlen($next) < 3) {
                throw new MikroTikConnectionException('Failed to read length bytes');
            }

            return (($ord & 0x0F) << 24) | (ord($next[0]) << 16) | (ord($next[1]) << 8) | ord($next[2]);
        }

        $next = @fread($this->socket, 4);
        if ($next === false || strlen($next) < 4) {
            throw new MikroTikConnectionException('Failed to read length bytes');
        }

        return (ord($next[0]) << 24) | (ord($next[1]) << 16) | (ord($next[2]) << 8) | ord($next[3]);
    }
}
