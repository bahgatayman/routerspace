<?php

namespace App\Support;

use App\Exceptions\InvalidIdempotencyKeyException;
use App\Models\IdempotencyKey;
use Illuminate\Http\Request;

/**
 * Persistent once-only guard for booking creation and payment recording.
 *
 * The client sends one key per user action (Idempotency-Key or
 * X-Idempotency-Key header, or an `idempotency_key` form field). The caller:
 *
 *   1. validates the request, then computes fingerprint() over the validated
 *      payload;
 *   2. calls claim() as the FIRST write inside the same DB::transaction() as
 *      the write it protects — so the claim commits only together with that
 *      write, and a rejected or failed attempt leaves no row (its key can be
 *      retried and is evaluated fresh);
 *   3. on Illuminate\Database\UniqueConstraintViolationException (caught
 *      outside the transaction) reads find(): same fingerprint → replay the
 *      original result, different fingerprint → reject as a conflict.
 *
 * A concurrent second copy of the same key blocks on the unique index (MySQL)
 * or the database write lock (SQLite) until the first commits or rolls back,
 * so only one of them can ever perform the write.
 *
 * Keys are scoped to (owner, actor, scope). Requests without a key are not
 * guarded at all — callers keep their previous behaviour for them.
 */
final class RequestIdempotency
{
    public const TTL_HOURS = 24;

    public const SCOPE_BOOKING_CREATE = 'booking.create';

    public const SCOPE_BOOKING_PAYMENT = 'booking.payment';

    /** Same length limit as the item endpoints' App\Support\IdempotencyKey; safe characters only. */
    private const PATTERN = '/^[A-Za-z0-9_-]{1,100}$/';

    /**
     * The request's key, or null when it carries none.
     *
     * @throws InvalidIdempotencyKeyException
     */
    public static function keyFrom(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key');

        if ($key === null || $key === '') {
            return null;
        }

        if (! is_string($key) || ! preg_match(self::PATTERN, $key)) {
            throw new InvalidIdempotencyKeyException;
        }

        return $key;
    }

    /** SHA-256 of the payload as canonical JSON (keys sorted at every level). */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Insert the claim. Call inside the protected write's transaction. */
    public static function claim(int $ownerId, string $scope, string $key, string $hash, ?int $bookingId = null): IdempotencyKey
    {
        [$actorType, $actorId] = self::actor($ownerId);

        // An expired claim no longer guards anything; free its slot so the key can be reused.
        IdempotencyKey::where(['owner_id' => $ownerId, 'actor_type' => $actorType, 'actor_id' => $actorId, 'scope' => $scope, 'key' => $key])
            ->where('expires_at', '<', now())
            ->delete();

        return IdempotencyKey::create([
            'owner_id' => $ownerId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'scope' => $scope,
            'key' => $key,
            'request_hash' => $hash,
            'booking_id' => $bookingId,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
    }

    /** The committed, unexpired claim for this key, if any. */
    public static function find(int $ownerId, string $scope, string $key): ?IdempotencyKey
    {
        [$actorType, $actorId] = self::actor($ownerId);

        return IdempotencyKey::where([
            'owner_id' => $ownerId, 'actor_type' => $actorType, 'actor_id' => $actorId, 'scope' => $scope, 'key' => $key,
        ])->where('expires_at', '>=', now())->first();
    }

    /** @return array{0: string, 1: int} The signed-in Panel actor: a staff member, else the owner. */
    private static function actor(int $ownerId): array
    {
        $staff = auth('staff')->user();

        return $staff ? ['staff', (int) $staff->id] : ['owner', $ownerId];
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
