@extends('layouts.app')

{{-- Every recurring booking of this owner: schedule, next date, dates ahead, unpaid, status. --}}
@section('page-title', __('app.series.index_title'))

@section('content')
    <div class="ls-series-page">
        <nav class="ls-wizard-crumbs" aria-label="{{ __('app.workspace.wizard.breadcrumb') }}">
            <a href="/bookings" class="ls-link">{{ __('app.booking.bookings') }}</a>
        </nav>

        <x-ui.page-header :title="__('app.series.index_title')" :subtitle="__('app.series.index_subtitle')">
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" href="/bookings/create">{{ __('app.series.new') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.flash />

        @if ($series->isEmpty())
            <div class="ls-card">
                <x-ui.empty-state illustration="calendar" :title="__('app.series.empty_title')" :text="__('app.series.empty_text')" />
            </div>
        @else
            <ul class="ls-series-list">
                @foreach ($series as $s)
                    <li>
                        <a href="{{ route('bookings.series.show', $s) }}" class="ls-card ls-series-item {{ $s->isActive() ? '' : 'is-stopped' }}">
                            <div class="ls-series-item-main">
                                <b>{{ $s->hotspotUser?->name }}</b>
                                <span class="ls-faint">{{ $s->room?->workspace?->name }} / {{ $s->room?->name }}</span>
                                <span>{{ $s->frequencyLabel() }} · <bdi dir="ltr">{{ $s->timeRange() }}</bdi> · {{ $s->endLabel() }}</span>
                            </div>
                            <div class="ls-series-item-side">
                                @if ($s->isActive())
                                    <x-ui.badge tone="ok" :dot="true">{{ __('app.series.statuses.active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="neutral" :dot="false">{{ __('app.series.statuses.stopped') }}</x-ui.badge>
                                @endif
                                <span class="ls-hint">
                                    {{ __('app.series.next') }}: {{ $s->next_date ? \Carbon\Carbon::parse($s->next_date)->translatedFormat('D j M') : '—' }}
                                    · {{ trans_choice('app.series.upcoming', $s->upcoming_count, ['count' => $s->upcoming_count]) }}
                                </span>
                                @if ($s->unpaid_count)
                                    <x-ui.badge tone="warn" :dot="false">{{ trans_choice('app.series.unpaid', $s->unpaid_count, ['count' => $s->unpaid_count]) }}</x-ui.badge>
                                @endif
                                @if (! empty($s->unresolved))
                                    <x-ui.badge tone="danger" :dot="false">{{ __('app.series.needs_attention') }}</x-ui.badge>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="ls-series-pages">{{ $series->links() }}</div>
        @endif
    </div>
@endsection
