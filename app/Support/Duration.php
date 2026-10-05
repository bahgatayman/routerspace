<?php

namespace App\Support;

/** Minutes → "18h 30m" / "45m" / "2h" (bilingual units from app.ui.unit_*). */
final class Duration
{
    public static function label(int $minutes): string
    {
        $sign = $minutes < 0 ? '−' : '';
        $minutes = abs($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        $parts = [];
        if ($h) {
            $parts[] = $h.__('app.ui.unit_h');
        }
        if ($m || ! $h) {
            $parts[] = $m.__('app.ui.unit_m');
        }

        return $sign.implode(' ', $parts);
    }

    /** "1h 30m" → used in messages; same as label() but never signed. */
    public static function plain(int $minutes): string
    {
        return self::label(abs($minutes));
    }

    /** Minutes a package draw should claim for $totalMinutes elapsed: rounded up, never zero. */
    public static function packageMinutes(float $totalMinutes): int
    {
        return max(1, (int) ceil($totalMinutes - 0.0001));
    }
}
