<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Services\AnalyticsPeriod;
use Illuminate\Http\Request;

/**
 * The admin period filter: ?preset=today|last_7|last_30|last_90|this_month|previous_month|custom
 * (+ from/to for custom, max 3 years). Invalid input falls back to the default
 * preset instead of erroring, so a stale bookmarked URL still opens.
 */
trait ResolvesPeriod
{
    public const PRESETS = ['today', 'last_7', 'last_30', 'last_90', 'this_month', 'previous_month', 'custom'];

    /** @return array{0: AnalyticsPeriod, 1: array{preset: string, from: ?string, to: ?string}} */
    protected function resolvePeriod(Request $request, string $default = 'last_30'): array
    {
        $preset = in_array($request->query('preset'), self::PRESETS, true) ? $request->query('preset') : $default;
        $from = $this->validDate($request->query('from'));
        $to = $this->validDate($request->query('to'));

        if ($preset === 'custom') {
            if (! $from || ! $to) {
                $preset = $default;
            } else {
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                if (now()->parse($from)->diffInDays(now()->parse($to)) > 366 * 3) {
                    $from = now()->parse($to)->subYears(3)->toDateString();
                }
            }
        }

        $period = AnalyticsPeriod::fromPreset($preset, $from, $to);

        return [$period, ['preset' => $preset, 'from' => $preset === 'custom' ? $from : $period->startDate(), 'to' => $preset === 'custom' ? $to : $period->endDate()]];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
