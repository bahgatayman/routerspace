<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Single source of truth for shared-session pricing — used by both
 * closePreview() and close(), which previously duplicated this formula
 * verbatim with a comment warning "must never disagree." 'minute' billing
 * is the original continuous formula (exact proportional charge, unchanged
 * for backward compatibility). 'half_hour'/'hour' charge for every started
 * block, rounded up — the only interpretation under which choosing a block
 * mode actually changes anything (a rounded-fraction-of-a-block charge would
 * be mathematically identical to per-minute billing).
 *
 * Billing Buffer (grace period, block billing only): the owner may allow up
 * to N minutes past each block boundary before the next block is charged.
 * With hourly blocks and a 15-minute buffer:
 *   0–1h15m → 1 hour · 1h16m–2h15m → 2 hours · 2h16m–3h15m → 3 hours …
 * The grace is counted in whole elapsed minutes (1h 15m 59s is still inside a
 * 15-minute grace; 1h 16m 00s is not), so what the owner reads on the timer
 * is exactly what decides the charge. Any started session costs at least one
 * block. Buffer 0 keeps the original formula bit-for-bit.
 */
class SharedSessionBillingService
{
    private const UNIT_MINUTES = [
        'minute' => 1,
        'half_hour' => 30,
        'hour' => 60,
    ];

    /** Choices offered in the room form (minutes); any 0…MAX value is accepted as Custom. */
    public const BUFFER_PRESETS = [0, 5, 10, 15, 30];

    /** Hard ceiling for a custom buffer; it must also stay below the block length. */
    public const MAX_BUFFER_MINUTES = 59;

    /**
     * @return array{total_minutes: float, billed_minutes: float, total_price: float}
     */
    public function calculate(Carbon $openedAt, Carbon $closedAt, string $billingUnit, float $pricePerHour, int $bufferMinutes = 0): array
    {
        // Carbon 3's diff is signed: a start after $closedAt (bad clock data)
        // would otherwise produce negative minutes — and a negative charge.
        $totalMinutes = max(0.0, round($openedAt->diffInSeconds($closedAt) / 60, 2));
        $unitMinutes = self::UNIT_MINUTES[$billingUnit] ?? 1;

        // Continuous: no blocks, exact proportional charge — today's
        // original formula, bit-for-bit. A buffer has no meaning here.
        if ($unitMinutes === 1) {
            $totalHours = round($totalMinutes / 60, 4);

            return [
                'total_minutes' => $totalMinutes,
                'billed_minutes' => $totalMinutes,
                'total_price' => round($totalHours * $pricePerHour, 2),
            ];
        }

        // Block billing: every started block counts in full, rounded up —
        // after the optional grace period past each boundary.
        $blocks = $this->blocks($totalMinutes, $unitMinutes, $this->effectiveBuffer($bufferMinutes, $unitMinutes));
        $billedMinutes = $blocks * $unitMinutes;
        $totalHours = round($billedMinutes / 60, 4);

        return [
            'total_minutes' => $totalMinutes,
            'billed_minutes' => (float) $billedMinutes,
            'total_price' => round($totalHours * $pricePerHour, 2),
        ];
    }

    /**
     * When the running bill next goes up (the next block starts being
     * charged), or null for continuous per-minute billing. Mirrors blocks():
     * with no buffer the next block starts the instant a boundary is passed;
     * with a buffer, at boundary + buffer + 1 whole minute.
     */
    public function nextChargeAt(Carbon $openedAt, Carbon $now, string $billingUnit, int $bufferMinutes = 0): ?Carbon
    {
        $unitMinutes = self::UNIT_MINUTES[$billingUnit] ?? 1;
        // A start after $now (bad clock data) has no meaningful "next hour" —
        // never show a countdown that contradicts a 0m timer.
        if ($unitMinutes === 1 || $openedAt->gt($now)) {
            return null;
        }

        $buffer = $this->effectiveBuffer($bufferMinutes, $unitMinutes);
        $totalMinutes = round($openedAt->diffInSeconds($now) / 60, 2);
        $blocks = max(1, $this->blocks($totalMinutes, $unitMinutes, $buffer));
        $threshold = $blocks * $unitMinutes + ($buffer > 0 ? $buffer + 1 : 0);

        return $openedAt->copy()->addMinutes($threshold);
    }

    public function unitMinutes(string $billingUnit): int
    {
        return self::UNIT_MINUTES[$billingUnit] ?? 1;
    }

    /** A buffer only applies to block billing and must stay shorter than one block. */
    public function effectiveBuffer(int $bufferMinutes, int $unitMinutes): int
    {
        if ($unitMinutes <= 1) {
            return 0;
        }

        return max(0, min($bufferMinutes, $unitMinutes - 1, self::MAX_BUFFER_MINUTES));
    }

    /** Chargeable blocks for $totalMinutes of use. Zero time costs nothing; any started session ≥ 1 block. */
    private function blocks(float $totalMinutes, int $unitMinutes, int $buffer): int
    {
        if ($totalMinutes <= 0) {
            return 0;
        }

        if ($buffer === 0) {
            return (int) ceil($totalMinutes / $unitMinutes);
        }

        $beyondGrace = floor($totalMinutes) - $buffer;

        return max(1, (int) ceil($beyondGrace / $unitMinutes));
    }
}
