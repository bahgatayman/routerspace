<?php

namespace App\Support;

/**
 * Egyptian mobile number normalization. No PHP library was added for this —
 * libphonenumber-class packages (e.g. giggsey/libphonenumber-for-php) are a
 * multi-MB dependency pulling in a generated metadata table for every country
 * on earth; this app serves Egyptian hotspot/workspace owners only (currency
 * is hardcoded EGP app-wide, per CLAUDE.md), so a focused ~10-line normalizer
 * for the one country actually in scope is less risk than a new Composer
 * dependency for 195 countries nothing here needs. If multi-country support
 * is ever genuinely required, that calculus changes and a library becomes
 * the right call.
 *
 * Canonical form: digits only, no "+", in "20" + subscriber-number form
 * (e.g. "201012345678") — this is what every valid input shape collapses to:
 *   "01012345678"    (11 digits, local)       -> "201012345678"
 *   "1012345678"     (10 digits, no leading 0) -> "201012345678"
 *   "201012345678"   (already canonical)       -> "201012345678"
 *   "+201012345678"  (E.164 display)            -> "201012345678"
 *
 * Deliberately no "+" in the canonical form — it's used as a RouterOS
 * hotspot username (see HotspotUserController), and a leading "+" in a
 * captive-portal login field is an avoidable footgun for no benefit.
 */
class PhoneNumber
{
    /** Egyptian mobile prefixes (010/011/012/015), the only numbers this app provisions on a router. */
    private const MOBILE_PREFIXES = ['10', '11', '12', '15'];

    /**
     * Canonical digits-only form, or null when the input doesn't look like
     * a recognizable Egyptian mobile number — callers must treat null as
     * "couldn't confidently normalize," not as an error: the raw value is
     * still usable, just without the cross-format dedup/canonical benefits.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // "0101234567 8" -> already stripped of the "+" above; drop a
        // country-code "20" prefix if present so every shape lands on the
        // same bare 10-digit subscriber number before re-adding "20" once.
        if (str_starts_with($digits, '20') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        // Local form "0XXXXXXXXXX" (11 digits) -> drop the leading 0.
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        // Must now be exactly the 10-digit subscriber number: prefix + 8 digits.
        if (strlen($digits) !== 10) {
            return null;
        }

        if (! in_array(substr($digits, 0, 2), self::MOBILE_PREFIXES, true)) {
            return null;
        }

        return '20'.$digits;
    }
}
