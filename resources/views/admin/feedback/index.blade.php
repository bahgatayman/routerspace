@extends('layouts.admin')

@section('page-title', __('app.feedback.admin.title'))

@php
    use App\Models\Feedback;
    use Illuminate\Support\Str;
    $a = fn (string $k, array $r = []) => __('app.feedback.admin.'.$k, $r);
    $f = fn (string $k, array $r = []) => __('app.feedback.'.$k, $r);
    $statusTone = ['new' => 'info', 'reviewing' => 'warn', 'planned' => 'info', 'completed' => 'ok', 'rejected' => 'neutral'];
    $catTone = ['general' => 'neutral', 'feature' => 'info', 'bug' => 'danger', 'complaint' => 'warn', 'other' => 'neutral'];
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
    $sortUrl = fn (string $s) => request()->fullUrlWithQuery(['sort' => $s, 'page' => null]);
    $avg = $stats['avg'];
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $a('title') }} <span class="ls-count">{{ number_format($stats['total']) }}</span></h1>
            <p class="ls-subtitle">{{ $a('subtitle') }}</p>
        </div>
        <div class="ls-actions">
            <a href="{{ route('feedback.create') }}" class="ls-btn ls-btn--secondary" target="_blank" rel="noopener"><x-ui.icon name="eye" />/feedback</a>
        </div>
    </header>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $a('total'), 'value' => number_format($stats['total'])])
        @include('admin.partials.stat', ['label' => $a('avg_rating'), 'value' => $avg === null ? '—' : number_format($avg, 1), 'sub' => $avg === null ? null : $a('out_of')])
        @include('admin.partials.stat', ['label' => $a('unreviewed'), 'value' => number_format($stats['unreviewed']), 'tone' => $stats['unreviewed'] > 0 ? 'warn' : null, 'href' => route('admin.feedback.index', ['status' => 'new'])])
        @include('admin.partials.stat', ['label' => $a('features'), 'value' => number_format($stats['features']), 'href' => route('admin.feedback.index', ['category' => 'feature'])])
        @include('admin.partials.stat', ['label' => $a('problems'), 'value' => number_format($stats['problems']), 'tone' => $stats['problems'] > 0 ? 'danger' : null])
    </div>

    <div class="ls-adm-grid">
        {{-- Rating distribution: plain bars + numbers (5 values don't need a chart library). --}}
        <section class="ls-card" aria-labelledby="fb-dist-title">
            <div class="ls-card-head"><h2 class="ls-card-title" id="fb-dist-title">{{ $a('distribution') }}</h2></div>
            <div class="ls-card-body">
                @if ($stats['total'] === 0)
                    <div class="ls-chart-empty"><x-ui.icon name="alert" /><span>{{ __('app.admin_platform.chart.empty') }}</span></div>
                @else
                    <div class="ls-fbd-avg">
                        <b class="ls-num">{{ number_format($avg, 1) }}</b>
                        <span class="ls-fbd-stars" aria-hidden="true">@for ($i = 1; $i <= 5; $i++)<x-ui.icon name="star" class="{{ $i <= round($avg) ? 'is-on' : '' }}" />@endfor</span>
                        <small>{{ number_format($stats['total']) }} {{ $a('total') }}</small>
                    </div>
                    <ul class="ls-fbd-bars">
                        @foreach ($distribution as $stars => $d)
                            <li>
                                <a href="{{ route('admin.feedback.index', ['rating' => $stars]) }}" class="ls-fbd-row" aria-label="{{ trans_choice('app.feedback.rating.star', $stars, ['count' => $stars]) }}: {{ $d['count'] }} ({{ $d['pct'] }}%)">
                                    <span class="ls-fbd-label ls-num">{{ $stars }} <x-ui.icon name="star" class="is-on" /></span>
                                    <span class="ls-fbd-track"><span class="ls-fbd-fill is-{{ $stars }}" style="width: {{ $d['pct'] }}%"></span></span>
                                    <span class="ls-fbd-n ls-num">{{ number_format($d['count']) }}</span>
                                    <span class="ls-fbd-pct ls-num">{{ rtrim(rtrim(number_format($d['pct'], 1), '0'), '.') }}%</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        @include('admin.partials.chart', ['id' => 'fb-vol', 'title' => $a('volume'), 'note' => $a('volume_note'), 'spec' => $charts['volume']])
        @include('admin.partials.chart', ['id' => 'fb-rate', 'title' => $a('rating_trend'), 'note' => $a('rating_trend_note'), 'spec' => $charts['rating']])
    </div>

    {{-- Full width: six numeric columns don't fit half a row. --}}
    <section class="ls-card" aria-labelledby="fb-areas-title">
            <div class="ls-card-head">
                <div>
                    <h2 class="ls-card-title" id="fb-areas-title">{{ $a('areas') }}</h2>
                    <p class="ls-chart-note">{{ $a('areas_note') }}</p>
                </div>
            </div>
            <div class="ls-card-body">
                @if (empty($areas))
                    <div class="ls-chart-empty"><x-ui.icon name="alert" /><span>{{ __('app.admin_platform.chart.empty') }}</span></div>
                @else
                    <div class="ls-table-wrap">
                        <table class="ls-table">
                            <thead><tr>
                                <th scope="col">{{ $a('col_area') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_responses') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_avg') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_low') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_problems') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_requests') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($areas as $row)
                                    <tr>
                                        <th scope="row" style="font-weight:500">
                                            @if ($row['area'])
                                                <a href="{{ route('admin.feedback.index', ['area' => $row['area']]) }}" class="ls-link">{{ $f('area.options.'.$row['area']) }}</a>
                                            @else
                                                <span class="ls-faint">{{ $a('unspecified') }}</span>
                                            @endif
                                        </th>
                                        <td class="is-num">{{ number_format($row['total']) }}</td>
                                        <td class="is-num"><span class="ls-fbd-score {{ $row['avg'] < 3 ? 'is-low' : ($row['avg'] >= 4 ? 'is-high' : '') }}">{{ number_format($row['avg'], 1) }}</span></td>
                                        <td class="is-num">{{ number_format($row['low']) }}</td>
                                        <td class="is-num">{{ number_format($row['problems']) }}</td>
                                        <td class="is-num">{{ number_format($row['features']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

    <section aria-labelledby="fb-list-title" class="ls-adm">
        <h2 class="ls-adm-section" id="fb-list-title">{{ $a('list') }}</h2>

        <nav class="ls-chips" aria-label="{{ $a('status') }}">
            <a href="{{ $chip(null) }}" class="ls-chip {{ $filters['status'] ? '' : 'is-active' }}">{{ __('app.common.all') }} <span class="ls-chip-count">{{ $stats['total'] }}</span></a>
            @foreach (Feedback::STATUSES as $s)
                <a href="{{ $chip($s) }}" class="ls-chip {{ $filters['status'] === $s ? 'is-active' : '' }}">{{ $a('statuses.'.$s) }} <span class="ls-chip-count">{{ (int) ($statusCounts[$s] ?? 0) }}</span></a>
            @endforeach
        </nav>

        <form method="GET" class="ls-adm-filters" aria-label="{{ __('app.admin_platform.filters') }}">
            @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <input type="hidden" name="sort" value="{{ $sort }}">
            <div class="ls-field ls-filter-field ls-filter-field--grow">
                <label class="ls-label" for="fb-q">{{ __('app.common.search') }}</label>
                <div class="ls-search">
                    <x-ui.icon name="search" />
                    <input id="fb-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $a('search_ph') }}" autocomplete="off">
                </div>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="fb-rating">{{ $a('avg_rating') }}</label>
                <select id="fb-rating" name="rating" class="ls-select">
                    <option value="">{{ $a('all_ratings') }}</option>
                    @for ($i = 5; $i >= 1; $i--)<option value="{{ $i }}" @selected($filters['rating'] === $i)>{{ trans_choice('app.feedback.rating.star', $i, ['count' => $i]) }}</option>@endfor
                </select>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="fb-cat">{{ __('app.admin_platform.col.type') }}</label>
                <select id="fb-cat" name="category" class="ls-select">
                    <option value="">{{ $a('all_categories') }}</option>
                    @foreach (Feedback::CATEGORIES as $c)<option value="{{ $c }}" @selected($filters['category'] === $c)>{{ $f('category.options.'.$c) }}</option>@endforeach
                </select>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="fb-area">{{ $a('col_area') }}</label>
                <select id="fb-area" name="area" class="ls-select">
                    <option value="">{{ $a('all_areas') }}</option>
                    @foreach (Feedback::AREAS as $ar)<option value="{{ $ar }}" @selected($filters['area'] === $ar)>{{ $f('area.options.'.$ar) }}</option>@endforeach
                </select>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="fb-from">{{ $a('from') }}</label>
                <input id="fb-from" type="date" name="from" value="{{ $filters['from'] }}" class="ls-input" dir="ltr">
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="fb-to">{{ $a('to') }}</label>
                <input id="fb-to" type="date" name="to" value="{{ $filters['to'] }}" class="ls-input" dir="ltr">
            </div>
            <div class="ls-filter-actions">
                <button type="submit" class="ls-btn ls-btn--primary">{{ __('app.admin_platform.apply') }}</button>
                @if ($hasFilters)<a href="{{ route('admin.feedback.index') }}" class="ls-btn ls-btn--ghost">{{ __('app.admin_platform.reset') }}</a>@endif
            </div>
        </form>

        @if ($items->isEmpty())
            <section class="ls-card">
                <x-ui.empty-state :title="$hasFilters ? $a('no_match') : $a('empty')" :text="$hasFilters ? null : $a('empty_text')">
                    @if ($hasFilters)<a href="{{ route('admin.feedback.index') }}" class="ls-btn ls-btn--secondary">{{ __('app.admin_platform.reset') }}</a>@endif
                </x-ui.empty-state>
            </section>
        @else
            <div class="ls-wsd-bar">
                <span class="ls-faint">{{ __('app.admin_platform.showing', ['from' => $items->firstItem(), 'to' => $items->lastItem(), 'total' => $items->total()]) }}</span>
                <label class="ls-wsd-sort">
                    <span>{{ __('app.workspace.sort_by') }}</span>
                    <select class="ls-select" onchange="location.href = this.value">
                        @foreach (['newest', 'oldest', 'rating_high', 'rating_low'] as $s)
                            <option value="{{ $sortUrl($s) }}" @selected($sort === $s)>{{ $a('sort.'.$s) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <ul class="ls-fb-list">
                @foreach ($items as $item)
                    <li class="ls-fb-item {{ $item->isNew() ? 'is-new' : '' }}">
                        <div class="ls-fb-rating" aria-label="{{ trans_choice('app.feedback.rating.star', $item->rating, ['count' => $item->rating]) }}">
                            <b class="ls-num">{{ $item->rating }}</b><x-ui.icon name="star" class="is-on" />
                        </div>
                        <div class="ls-fb-main">
                            <div class="ls-fb-tags">
                                @if ($item->isNew())<span class="ls-fb-dot">{{ $a('new_badge') }}</span>@endif
                                <x-ui.badge :tone="$catTone[$item->category] ?? 'neutral'" :dot="false">{{ $f('category.options.'.$item->category) }}</x-ui.badge>
                                @if ($item->product_area)<span class="ls-fb-area">{{ $f('area.options.'.$item->product_area) }}</span>@endif
                            </div>
                            <a href="{{ route('admin.feedback.show', $item) }}" class="ls-fb-msg" dir="auto">{{ Str::limit($item->message, 220) }}</a>
                            <div class="ls-fb-meta">
                                <span><bdi>{{ $item->contact_name ?: ($item->contact_email ?: $a('anonymous')) }}</bdi></span>
                                @if ($item->owner)<span>· {{ $item->owner->business_name ?: $item->owner->name }}</span>@endif
                                <span>· {{ $item->created_at->diffForHumans() }}</span>
                            </div>
                        </div>
                        <div class="ls-fb-side">
                            <x-ui.badge :tone="$statusTone[$item->status] ?? 'neutral'">{{ $a('statuses.'.$item->status) }}</x-ui.badge>
                            <a href="{{ route('admin.feedback.show', $item) }}" class="ls-btn ls-btn--ghost ls-btn--sm">{{ $a('open') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="ls-adm-pager">
                <span></span>
                {{ $items->onEachSide(1)->links('admin.partials.pager') }}
            </div>
        @endif
    </section>
</div>
@endsection
