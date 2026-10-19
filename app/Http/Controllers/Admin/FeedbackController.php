<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Super Admin → Feedback. Platform-wide by design (feedback is about the
 * product, not a tenant), so it lives only behind auth:admin. Every number is
 * an aggregate of stored rows; nothing is estimated or "AI-summarised".
 */
class FeedbackController extends Controller
{
    private const SORTS = ['newest', 'oldest', 'rating_high', 'rating_low'];

    private const TREND_MONTHS = 6;

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'rating' => in_array((int) $request->query('rating'), [1, 2, 3, 4, 5], true) ? (int) $request->query('rating') : null,
            'category' => in_array($request->query('category'), Feedback::CATEGORIES, true) ? $request->query('category') : null,
            'area' => in_array($request->query('area'), Feedback::AREAS, true) ? $request->query('area') : null,
            'status' => in_array($request->query('status'), Feedback::STATUSES, true) ? $request->query('status') : null,
            'from' => $this->validDate($request->query('from')),
            'to' => $this->validDate($request->query('to')),
        ];
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'newest';

        $list = $this->filtered($filters)
            ->with('owner:id,business_name,name')
            ->when($sort === 'oldest', fn ($q) => $q->orderBy('created_at')->orderBy('id'))
            ->when($sort === 'rating_high', fn ($q) => $q->orderByDesc('rating')->orderByDesc('id'))
            ->when($sort === 'rating_low', fn ($q) => $q->orderBy('rating')->orderByDesc('id'))
            ->when($sort === 'newest', fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'))
            ->paginate(20)
            ->withQueryString();

        return view('admin.feedback.index', [
            'items' => $list,
            'filters' => $filters,
            'sort' => $sort,
            'hasFilters' => collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty(),
            'stats' => $this->stats(),
            'distribution' => $this->distribution(),
            'charts' => $this->trend(),
            'areas' => $this->areaInsights(),
            'statusCounts' => Feedback::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Feedback $feedback): View
    {
        $feedback->load(['owner:id,business_name,name,email', 'staff:id,name', 'reviewer:id,name']);

        return view('admin.feedback.show', ['item' => $feedback]);
    }

    /** Only the review fields change; what the visitor wrote is never touched. */
    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Feedback::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $notes = filled($data['admin_notes'] ?? null) ? trim($data['admin_notes']) : null;
        $changed = $data['status'] !== $feedback->status || $notes !== $feedback->admin_notes;

        if ($changed) {
            $feedback->forceFill([
                'status' => $data['status'],
                'admin_notes' => $notes,
                'reviewed_at' => now(),
                'reviewed_by' => auth('admin')->id(),
            ])->save();
        }

        return redirect()->route('admin.feedback.show', $feedback)
            ->with('success', $changed ? __('app.feedback.admin.saved') : __('app.feedback.admin.no_changes'));
    }

    private function filtered(array $f): Builder
    {
        return Feedback::query()
            ->when($f['q'] !== '', function ($q) use ($f) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
                $q->where(fn ($w) => $w->where('message', 'like', $like)->orWhere('details', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)->orWhere('contact_email', 'like', $like));
            })
            ->when($f['rating'], fn ($q) => $q->where('rating', $f['rating']))
            ->when($f['category'], fn ($q) => $q->where('category', $f['category']))
            ->when($f['area'], fn ($q) => $q->where('product_area', $f['area']))
            ->when($f['status'], fn ($q) => $q->where('status', $f['status']))
            ->when($f['from'], fn ($q) => $q->whereDate('created_at', '>=', $f['from']))
            ->when($f['to'], fn ($q) => $q->whereDate('created_at', '<=', $f['to']));
    }

    /** Headline numbers over ALL feedback (the list below is what filters narrow). */
    private function stats(): array
    {
        $row = Feedback::query()->selectRaw(
            "COUNT(*) as total, AVG(rating) as avg_rating,
             SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as unreviewed,
             SUM(CASE WHEN category = 'feature' THEN 1 ELSE 0 END) as features,
             SUM(CASE WHEN category IN ('bug', 'complaint') THEN 1 ELSE 0 END) as problems"
        )->first();

        return [
            'total' => (int) $row->total,
            'avg' => $row->total > 0 ? round((float) $row->avg_rating, 2) : null,
            'unreviewed' => (int) $row->unreviewed,
            'features' => (int) $row->features,
            'problems' => (int) $row->problems,
        ];
    }

    /** @return array<int, array{count:int, pct:float}> keyed 5..1 */
    private function distribution(): array
    {
        $counts = Feedback::query()->selectRaw('rating, COUNT(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $total = max(1, (int) $counts->sum());

        return collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($r) => [$r => [
            'count' => (int) ($counts[$r] ?? 0),
            'pct' => round(((int) ($counts[$r] ?? 0)) / $total * 100, 1),
        ]])->all();
    }

    /** Responses and average rating per calendar month (grouped in PHP: same on SQLite and MySQL). */
    private function trend(): array
    {
        $start = now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        $rows = Feedback::query()->where('created_at', '>=', $start)->get(['created_at', 'rating', 'category']);

        $months = collect(range(0, self::TREND_MONTHS - 1))->map(fn ($i) => $start->copy()->addMonths($i));
        $byMonth = $rows->groupBy(fn ($r) => $r->created_at->format('Y-m'));
        $labels = $months->map(fn (Carbon $m) => $m->translatedFormat('M Y'))->all();
        $count = fn (?array $cats = null) => $months->map(function (Carbon $m) use ($byMonth, $cats) {
            $set = $byMonth->get($m->format('Y-m'), collect());

            return $cats ? $set->whereIn('category', $cats)->count() : $set->count();
        })->all();

        return [
            'volume' => [
                'type' => 'bar', 'stacked' => true, 'labels' => $labels, 'axis' => __('app.feedback.admin.month'),
                'datasets' => [
                    ['label' => __('app.feedback.category.options.feature'), 'data' => $count(['feature']), 'color' => 'c1'],
                    ['label' => __('app.feedback.admin.bugs_complaints'), 'data' => $count(['bug', 'complaint']), 'color' => 'c3'],
                    ['label' => __('app.feedback.admin.other_feedback'), 'data' => $count(['general', 'other']), 'color' => 'c2'],
                ],
            ],
            'rating' => [
                'type' => 'line', 'labels' => $labels, 'axis' => __('app.feedback.admin.month'),
                'datasets' => [[
                    'label' => __('app.feedback.admin.avg_rating'),
                    // null (not 0) for months without responses: the line breaks instead of diving to zero.
                    'data' => $months->map(function (Carbon $m) use ($byMonth) {
                        $set = $byMonth->get($m->format('Y-m'));

                        return $set ? round((float) $set->avg('rating'), 2) : null;
                    })->all(),
                    'color' => 'c1',
                ]],
            ],
        ];
    }

    /** Per product area: volume, average rating, problems vs requests — lowest-rated first. */
    private function areaInsights(): array
    {
        return Feedback::query()
            ->selectRaw("COALESCE(product_area, '') as area, COUNT(*) as total, AVG(rating) as avg_rating,
                SUM(CASE WHEN category IN ('bug', 'complaint') THEN 1 ELSE 0 END) as problems,
                SUM(CASE WHEN category = 'feature' THEN 1 ELSE 0 END) as features,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as low")
            ->groupBy('area')
            ->get()
            ->map(fn ($r) => [
                'area' => $r->area ?: null,
                'total' => (int) $r->total,
                'avg' => round((float) $r->avg_rating, 2),
                'problems' => (int) $r->problems,
                'features' => (int) $r->features,
                'low' => (int) $r->low,
            ])
            ->sortBy([['avg', 'asc'], ['total', 'desc']])
            ->values()
            ->all();
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
