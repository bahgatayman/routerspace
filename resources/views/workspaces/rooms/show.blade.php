@extends('layouts.app')

@section('page-title', $room->name)

@section('content')
    {{--
        Read-only: a gallery-first view of one room, for staff who can see
        Rooms (workspaces.view) but not necessarily edit them. Occupancy here
        is a fresh single-room read, not the batched grid computation — fine
        at this scale (one room, one page view), unlike the list page which
        deliberately batches for N rooms.
    --}}
    @php
        $images = $room->images;
    @endphp
    <div style="margin-bottom: var(--space-4); font-size: 13px; color: var(--color-text-muted)">
        <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}" class="ls-link">{{ __('app.workspace.rooms_title') }}</a>
        <span aria-hidden="true"> / </span>
        <span>{{ $room->name }}</span>
    </div>

    <x-ui.page-header :title="$room->name" :subtitle="$room->typeLabel()">
        <x-slot:actions>
            <x-ui.button :href="route('rooms.edit', [$workspace, $room])" icon="edit">{{ __('app.common.edit') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="ls-room-photo" style="border-radius: var(--ls-r-card); margin-bottom: var(--space-5); aspect-ratio: 16/7" @if ($images->count() > 1) data-ls-gallery @endif>
        @if ($images->isEmpty())
            <div class="ls-room-photo-placeholder"><x-ui.icon name="building" /></div>
        @else
            <div class="ls-room-photo-track">
                @foreach ($images as $image)
                    <div class="ls-room-photo-slide"><img src="{{ $image->url() }}" loading="lazy" alt=""></div>
                @endforeach
            </div>
            @if ($images->count() > 1)
                <button type="button" class="ls-room-nav is-prev" aria-label="{{ __('app.workspace.previous_photo') }}"><x-ui.icon name="chevron-right" /></button>
                <button type="button" class="ls-room-nav is-next" aria-label="{{ __('app.workspace.next_photo') }}"><x-ui.icon name="chevron-right" /></button>
                <div class="ls-room-dots" role="tablist" aria-label="{{ __('app.workspace.room_photos') }}">
                    @foreach ($images as $i => $image)
                        <button type="button" class="ls-room-dot {{ $i === 0 ? 'is-active' : '' }}" aria-label="{{ __('app.workspace.photo_of', ['current' => $i + 1, 'total' => $images->count()]) }}"></button>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    <div class="ls-card">
        <div class="ls-card-body" style="display:grid;gap:var(--space-4);grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
            <div>
                <span class="ls-strip-label">{{ __('app.workspace.type') }}</span>
                <div class="ls-strip-value">{{ $room->typeLabel() }}</div>
            </div>
            <div>
                <span class="ls-strip-label">{{ __('app.workspace.capacity') }}</span>
                <div class="ls-strip-value">{{ $room->capacity }} {{ __('app.workspace.seats') }}</div>
            </div>
            <div>
                <span class="ls-strip-label">{{ __('app.inventory.pricing') }}</span>
                <div class="ls-strip-value">{{ $room->pricingSummary() }}</div>
            </div>
            <div>
                <span class="ls-strip-label">{{ __('app.workspace.available') }}</span>
                <div class="ls-strip-value">
                    @if (! $room->is_available)
                        <x-ui.badge tone="neutral" :dot="false">{{ __('app.workspace.unavailable') }}</x-ui.badge>
                    @elseif ($room->isShared())
                        {{ $room->statusLabel((int) $room->openSharedSessions()->sum('party_size')) }}
                    @else
                        <span class="ls-status"><span class="ls-dot"></span>{{ __('app.workspace.available') }}</span>
                    @endif
                </div>
            </div>
        </div>
        @if ($room->description)
            <div class="ls-card-body" style="padding-top:0">
                <span class="ls-strip-label">{{ __('app.workspace.description') }}</span>
                <p class="ls-faint" style="margin-top:4px">{{ $room->description }}</p>
            </div>
        @endif
    </div>
@endsection
