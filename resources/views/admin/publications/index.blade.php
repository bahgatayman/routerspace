@extends('layouts.admin')

@section('page-title', __('app.publication.admin.title'))

@php
    use App\Models\Workspace;
    $p = fn (string $k, array $r = []) => __('app.publication.'.$k, $r);
    $a = fn (string $k, array $r = []) => __('app.publication.admin.'.$k, $r);
    $tones = [Workspace::PENDING_REVIEW => 'info', Workspace::REJECTED => 'warn', Workspace::PUBLISHED => 'ok', Workspace::UNPUBLISHED => 'danger'];
    $tabs = [Workspace::PENDING_REVIEW, Workspace::PUBLISHED, Workspace::REJECTED, Workspace::UNPUBLISHED];
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $a('title') }}</h1>
            <p class="ls-subtitle">{{ $a('subtitle') }}</p>
        </div>
    </header>

    <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
        @foreach ($tabs as $tab)
            <a href="{{ route('admin.publications.index', ['status' => $tab]) }}" class="ls-chip {{ $status === $tab ? 'is-active' : '' }}" @if ($status === $tab) aria-current="page" @endif>
                {{ $p('status.'.$tab) }} <span class="ls-chip-count">{{ (int) ($counts[$tab] ?? 0) }}</span>
            </a>
        @endforeach
    </nav>

    @if ($workspaces->isEmpty())
        <section class="ls-card">
            <x-ui.empty-state :title="$status === Workspace::PENDING_REVIEW ? $a('empty_pending') : $a('empty')" />
        </section>
    @else
        <ul class="ls-pubq">
            @foreach ($workspaces as $ws)
                @php
                    $check = $readiness[$ws->id];
                    $primary = $ws->primaryImage();
                    $bookable = $ws->rooms->filter(fn ($r) => $r->is_available && $r->hasDisplayablePrice());
                @endphp
                <li class="ls-pubq-item">
                    @if ($primary)
                        <img src="{{ $primary->thumbnailUrl() }}" alt="" class="ls-pubq-thumb" loading="lazy">
                    @else
                        <span class="ls-pubq-thumb">{{ $a('no_photo') }}</span>
                    @endif
                    <div class="ls-pubq-main">
                        <a href="{{ route('admin.publications.show', $ws) }}" class="ls-pubq-name">{{ $ws->name }}</a>
                        <div class="ls-pubq-meta">
                            <span>{{ $ws->owner?->business_name ?: $ws->owner?->name }}</span>
                            @if ($ws->city)<span>· {{ $ws->city }}</span>@endif
                            <span>· {{ $a('eligible_rooms', ['count' => $bookable->count(), 'total' => $ws->rooms->count()]) }}</span>
                            @if ($bookable->isNotEmpty())<span>· {{ $bookable->first()->pricingSummary() }}</span>@endif
                        </div>
                        <div class="ls-pubq-meta">
                            @if ($check['ready'])
                                <x-ui.badge tone="ok">{{ $a('ready') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger">{{ trans_choice('app.publication.admin.missing', count($check['missing']), ['count' => count($check['missing'])]) }}</x-ui.badge>
                                <span>{{ collect($check['missing'])->map(fn ($k) => $p('checks.'.$k.'.label'))->implode(' · ') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="ls-pubq-side">
                        <x-ui.badge :tone="$tones[$ws->publicationStatus()] ?? 'neutral'">{{ $p('status.'.$ws->publicationStatus()) }}</x-ui.badge>
                        <span class="ls-faint" style="font-size:12px">{{ $a('updated', ['when' => $ws->updated_at->diffForHumans()]) }}</span>
                        <a href="{{ route('admin.publications.show', $ws) }}" class="ls-btn ls-btn--secondary ls-btn--sm">{{ $a('review') }}</a>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="ls-adm-pager"><span></span>{{ $workspaces->links('admin.partials.pager') }}</div>
    @endif
</div>
@endsection
