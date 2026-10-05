<?php

namespace App\Support;

use App\Services\MemberSearchService;

/**
 * Wraps the part of $text that matched a search $term in <mark>, for
 * rendering search results. Given just the plain field value and the typed
 * term, it re-derives the match itself:
 *
 *   - A literal (case-insensitive) substring match gets a precise highlight
 *     around exactly that substring.
 *   - No literal substring, but MemberSearchService::isFuzzyMatch() agrees
 *     THIS field is a genuine fuzzy match (not just some other field on the
 *     same row) — a single, subtle whole-field mark, since there's no exact
 *     position to pinpoint. Sharing that one rule with the search service
 *     itself matters: without it, every field on a fuzzily-matched row would
 *     get marked "fuzzy" just because the term is non-empty, including ones
 *     (e.g. the phone number) that had nothing to do with why the row
 *     matched at all.
 *   - Otherwise, plain escaped text — this field simply isn't why the row matched.
 */
final class Highlight
{
    public static function mark(?string $text, ?string $term): string
    {
        $text ??= '';
        $term = trim((string) $term);

        if ($term === '') {
            return e($text);
        }

        $pos = mb_stripos($text, $term);
        if ($pos === false) {
            return MemberSearchService::isFuzzyMatch($text, $term)
                ? '<mark class="ls-hl ls-hl--fuzzy">'.e($text).'</mark>'
                : e($text);
        }

        $before = mb_substr($text, 0, $pos);
        $match = mb_substr($text, $pos, mb_strlen($term));
        $after = mb_substr($text, $pos + mb_strlen($term));

        return e($before).'<mark class="ls-hl">'.e($match).'</mark>'.e($after);
    }
}
