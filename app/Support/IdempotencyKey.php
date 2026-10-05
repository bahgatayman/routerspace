<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Once-only guard for "add to bill" requests. The browser sends one
 * X-Idempotency-Key per user action; a retried or duplicated request carrying
 * the same key is acknowledged without being applied again. Cache::add() is
 * atomic (insert-if-absent), so two racing copies can't both win.
 */
final class IdempotencyKey
{
    private const TTL_SECONDS = 600;

    /** True the first time a key is seen (go ahead); false for a repeat. Requests without a key always proceed. */
    public static function claim(Request $request, string $scope): bool
    {
        $key = (string) $request->header('X-Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100) {
            return true;
        }

        return Cache::add('idem:'.TenantContext::id().':'.$scope.':'.$key, 1, self::TTL_SECONDS);
    }
}
