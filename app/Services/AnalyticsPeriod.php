<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * An immutable date window for the dashboard analytics services, plus its
 * own preceding window of equal length for "vs previous period" deltas.
 * Every analytics service method takes one of these instead of raw dates so
 * a comparison is just calling the method twice with $period and
 * $period->previous().
 */
final class AnalyticsPeriod
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
    ) {}

    public static function today(): self
    {
        $now = Carbon::now();

        return new self($now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today');
    }

    public static function thisWeek(): self
    {
        $now = Carbon::now();

        return new self($now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'this_week');
    }

    public static function thisMonth(): self
    {
        $now = Carbon::now();

        return new self($now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'this_month');
    }

    /** The last $days days including today (e.g. last 7 days). */
    public static function lastDays(int $days): self
    {
        $now = Carbon::now();

        return new self($now->copy()->subDays(max(1, $days) - 1)->startOfDay(), $now->copy()->endOfDay(), 'last_'.$days);
    }

    /** The last $months months up to and including today (e.g. 3 → today minus 3 months + 1 day … today). */
    public static function lastMonths(int $months): self
    {
        $now = Carbon::now();

        return new self($now->copy()->subMonthsNoOverflow(max(1, $months))->addDay()->startOfDay(), $now->copy()->endOfDay(), 'last_'.$months.'_months');
    }

    public static function previousMonth(): self
    {
        $start = Carbon::now()->startOfMonth()->subMonthNoOverflow();

        return new self($start->copy(), $start->copy()->endOfMonth(), 'previous_month');
    }

    /**
     * A filter preset by key (admin dashboards). Unknown keys fall back to
     * last 30 days; 'custom' uses $from/$to (Y-m-d, already validated).
     */
    public static function fromPreset(string $preset, ?string $from = null, ?string $to = null): self
    {
        return match ($preset) {
            'today' => self::today(),
            'last_7' => self::lastDays(7),
            'last_90' => self::lastDays(90),
            'this_month' => self::thisMonth(),
            'previous_month' => self::previousMonth(),
            'custom' => ($from && $to) ? self::custom(Carbon::parse($from), Carbon::parse($to)) : self::lastDays(30),
            default => self::lastDays(30),
        };
    }

    /** Whole days in this window. */
    public function days(): int
    {
        return (int) $this->start->copy()->startOfDay()->diffInDays($this->end->copy()->startOfDay()) + 1;
    }

    public static function custom(Carbon $start, Carbon $end, string $label = 'custom'): self
    {
        return new self($start->copy()->startOfDay(), $end->copy()->endOfDay(), $label);
    }

    /**
     * The immediately preceding window of equal length (in whole days) —
     * e.g. previous() of "this month" is not necessarily a full calendar
     * month, it's the same number of days shifted back. Simple and
     * consistent across every period type, matching the approved design's
     * "same calc, shifted date window" definition rather than trying to be
     * calendar-aware per period type.
     */
    public function previous(): self
    {
        // diffInDays() returns a float in this Carbon version (e.g. a
        // same-day period yields ~0.99998, not 0) — cast to int first, or
        // adding 1 and passing the float straight to subDays() rounds up
        // and silently shifts the window back by one extra day.
        $days = (int) $this->start->diffInDays($this->end) + 1;

        return new self(
            $this->start->copy()->subDays($days),
            $this->start->copy()->subDay()->endOfDay(),
            "{$this->label}_previous",
        );
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }
}
