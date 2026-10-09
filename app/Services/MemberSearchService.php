<?php

namespace App\Services;

use App\Models\HotspotUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Live member search for the Users page: name/phone/email, case-insensitive,
 * partial, with a bounded fuzzy fallback for small typos in the name (e.g.
 * "bahgt" still finds "Bahgat Ayman").
 *
 * Deliberately scores in PHP rather than pushing fuzzy matching into SQL
 * (no portable Levenshtein/similarity function across SQLite and MySQL
 * without a UDF). To keep every debounced keystroke cheap regardless of
 * dataset size, this loads only a lean id/name/phone/email projection for
 * the whole owner — bounded in practice by the owner's Plan::max_members,
 * not the kind of volume (tens of thousands+) where an in-PHP scan would
 * become a real cost. A `LIKE '%term%'` scan (what this replaces) already
 * can't use an index either, so this isn't trading away indexed lookups —
 * it's the same full-scan cost, just scored in PHP instead of the DB engine.
 */
class MemberSearchService
{
    /** A fuzzy-only (no literal substring) match below this score is too weak to surface — keeps results relevant, not "aggressive". */
    private const FUZZY_THRESHOLD = 55.0;

    /** Fuzzy scoring needs enough characters to mean anything; shorter terms stay literal-substring only. */
    private const FUZZY_MIN_TERM_LENGTH = 3;

    /**
     * Whether $text is a genuine (typo-tolerant) fuzzy match for $term, by
     * the exact same rule ranking uses — shared with App\Support\Highlight
     * so a field only gets the "fuzzy" visual treatment when it actually
     * contributed a fuzzy match, not just because the row matched via some
     * OTHER field (e.g. never mark a phone number as fuzzy-matched just
     * because the search term happened to match the name instead).
     */
    public static function isFuzzyMatch(?string $text, string $term): bool
    {
        if ($text === null || $text === '' || mb_strlen($term) < self::FUZZY_MIN_TERM_LENGTH) {
            return false;
        }

        return self::fuzzyScore($text, $term) >= self::FUZZY_THRESHOLD;
    }

    /** Best per-word similarity percentage between $text and $term — see nameScore()'s docblock for why per-word, not whole-string. */
    private static function fuzzyScore(string $text, string $term): float
    {
        $needle = mb_strtolower($term);
        $lowerText = mb_strtolower(trim($text));
        $words = array_filter(array_merge(preg_split('/\s+/', $lowerText) ?: [], [$lowerText]));

        $best = 0.0;
        foreach ($words as $word) {
            similar_text($word, $needle, $percent);
            $best = max($best, $percent);
        }

        return $best;
    }

    /**
     * @return LengthAwarePaginator<int, HotspotUser>
     */
    public function paginate(int $ownerId, string $term, int $perPage, int $page): LengthAwarePaginator
    {
        $term = trim($term);

        if ($term === '') {
            return $this->latest($ownerId, $perPage, $page);
        }

        $candidates = HotspotUser::where('owner_id', $ownerId)
            ->select(['id', 'name', 'phone', 'email', 'created_at'])
            ->get();

        $matches = $this->rank($candidates, $term);

        $total = $matches->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        // A stale/bookmarked page beyond the filtered result set lands back
        // on page 1 rather than rendering an empty table with no explanation
        // — matters most right after a search narrows the results while the
        // owner was deep in pagination.
        if ($page > $lastPage) {
            $page = 1;
        }

        $items = $matches->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * The unsearched list (newest first), paginated in SQL so only one page of
     * members is loaded, not the whole membership. Same order the in-memory
     * stable sort produced: created_at desc, ties by id, members without a
     * created_at last; same out-of-range-page fallback to page 1.
     *
     * @return LengthAwarePaginator<int, HotspotUser>
     */
    private function latest(int $ownerId, int $perPage, int $page): LengthAwarePaginator
    {
        $query = HotspotUser::where('owner_id', $ownerId)->select(['id', 'name', 'phone', 'email', 'created_at']);

        $total = (clone $query)->count();
        if ($page > max(1, (int) ceil($total / $perPage))) {
            $page = 1;
        }

        $items = $query->orderByRaw('created_at is null')->orderByDesc('created_at')->orderBy('id')
            ->forPage($page, $perPage)->get();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** @return Collection<int, HotspotUser> */
    private function rank(Collection $candidates, string $term): Collection
    {
        $needle = mb_strtolower($term);

        return $candidates
            ->map(fn (HotspotUser $user) => [$user, $this->score($user, $needle)])
            ->filter(fn (array $pair) => $pair[1] > 0)
            // PHP's sort is stable since 8.0, so equally-scored rows keep
            // their original (latest-first) relative order instead of
            // jittering between keystrokes.
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    private function score(HotspotUser $user, string $needle): float
    {
        return max(
            $this->nameScore($user->name, $needle),
            $this->substringMatch($user->phone, $needle) ? 100.0 : 0.0,
            $this->substringMatch((string) $user->email, $needle) ? 100.0 : 0.0,
        );
    }

    private function substringMatch(?string $haystack, string $needle): bool
    {
        return $haystack !== null && $haystack !== '' && mb_stripos($haystack, $needle) !== false;
    }

    /**
     * A literal substring anywhere in the name wins outright. Otherwise, a
     * bounded fuzzy pass — compared per word (and the whole name), not just
     * the whole name alone, so a typo in "Bahgat" isn't diluted by also
     * being measured against "Ayman": similar_text("bahgt","bahgat") scores
     * far higher than similar_text("bahgt","bahgat ayman") would.
     */
    private function nameScore(?string $name, string $needle): float
    {
        if ($name === null || $name === '') {
            return 0.0;
        }

        if (mb_stripos($name, $needle) !== false) {
            return 100.0;
        }

        return self::isFuzzyMatch($name, $needle) ? self::fuzzyScore($name, $needle) : 0.0;
    }
}
