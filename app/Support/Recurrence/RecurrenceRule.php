<?php

namespace App\Support\Recurrence;

use Carbon\CarbonImmutable;

/**
 * A booking series' repeat rule: daily, or weekly on chosen weekdays, from a
 * start date until an end date or for a number of dates. Pure date maths in
 * the app timezone on plain Y-m-d dates (no times), so there are no DST
 * edges. It only produces dates — whether a date can be booked is the
 * AvailabilityService's call, per occurrence.
 */
final class RecurrenceRule
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY];

    /**
     * Most dates created or reviewed in one operation (one request, or one
     * scheduled batch). A longer series is not refused: later dates are added
     * in further batches (RecurringBookingService::extend()).
     */
    public const BATCH = 60;

    /** Upper bound on "end after N dates" (a sanity limit on input, not on how far ahead a series may run). */
    public const MAX_COUNT = 1000;

    /**
     * @param  int[]  $weekdays  0 = Sunday … 6 = Saturday (Carbon dayOfWeek); weekly only
     */
    public function __construct(
        public readonly string $frequency,
        public readonly array $weekdays,
        public readonly CarbonImmutable $startsOn,
        public readonly ?CarbonImmutable $endsOn,
        public readonly ?int $count,
    ) {}

    public static function make(string $frequency, ?array $weekdays, string $startsOn, ?string $endsOn, ?int $count): self
    {
        $days = $frequency === self::WEEKLY
            ? array_values(array_unique(array_map('intval', $weekdays ?? [])))
            : [];
        sort($days);

        return new self(
            $frequency,
            $days,
            CarbonImmutable::parse($startsOn)->startOfDay(),
            $endsOn ? CarbonImmutable::parse($endsOn)->startOfDay() : null,
            $count,
        );
    }

    /**
     * The rule's dates (Y-m-d) strictly after $after (null = from the start),
     * at most $limit of them, none after $until when given. "End after N
     * dates" counts from the series start, so later batches continue the same
     * numbering.
     *
     * @return string[]
     */
    public function dates(?string $after = null, int $limit = self::BATCH, ?string $until = null): array
    {
        $out = [];
        $n = 0;
        // Weekly with no weekday never matches: without this guard a series
        // bounded only by a count would loop forever.
        if ($this->frequency === self::WEEKLY && $this->weekdays === []) {
            return [];
        }
        $afterDay = $after ? CarbonImmutable::parse($after)->startOfDay() : null;
        $untilDay = $until ? CarbonImmutable::parse($until)->startOfDay() : null;

        for ($day = $this->startsOn; count($out) < $limit; $day = $day->addDay()) {
            if ($this->endsOn && $day->gt($this->endsOn)) {
                break;
            }
            if ($untilDay && $day->gt($untilDay)) {
                break;
            }
            if (! $this->matches($day)) {
                continue;
            }
            $n++;
            if ($this->count !== null && $n > $this->count) {
                break;
            }
            if ($afterDay === null || $day->gt($afterDay)) {
                $out[] = $day->toDateString();
            }
        }

        return $out;
    }

    /** True when the rule has at least one date after $after. */
    public function hasDatesAfter(?string $after): bool
    {
        return $this->dates($after, 1) !== [];
    }

    private function matches(CarbonImmutable $day): bool
    {
        return $this->frequency === self::DAILY || in_array($day->dayOfWeek, $this->weekdays, true);
    }
}
