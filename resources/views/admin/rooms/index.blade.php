@extends('layouts.admin')

@section('page-title', __('app.admin_platform.nav.rooms'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $hasFilters = request()->hasAny(['q', 'owner', 'location', 'type', 'available', 'idle', 'preset']);
    // Availability chips replace the old select + "idle only" checkbox (same query params).
    $chipUrl = fn (array $q) => request()->fullUrlWithQuery(array_merge(['available' => null, 'idle' => null, 'page' => null], $q));
    $chips = [
        'all' => ['label' => __('app.common.all'), 'url' => $chipUrl([]), 'on' => $filters['available'] === null && ! $filters['idle']],
        'available' => ['label' => $t('available'), 'url' => $chipUrl(['available' => '1']), 'on' => $filters['available'] === '1' && ! $filters['idle']],
        'unavailable' => ['label' => $t('unavailable'), 'url' => $chipUrl(['available' => '0']), 'on' => $filters['available'] === '0'],
        'idle' => ['label' => $t('idle_only'), 'url' => $chipUrl(['idle' => '1']), 'on' => $filters['idle']],
    ];
    $sortOptions = collect(['bookings_desc', 'earnings_desc', 'name_asc', 'capacity_desc', 'price_desc', 'price_asc', 'created_desc'])
        ->mapWithKeys(function ($o) {
            [$key, $dir] = explode('_', $o);

            return [$o => request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null])];
        });
    $currentSort = $sort.'_'.$dir;
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('nav.rooms') }} <span class="ls-count">{{ number_format($rooms->total()) }}</span></h1>
            <p class="ls-subtitle">{{ $t('rooms_sub') }}</p>
        </div>
    </header>

    <nav class="ls-chips" aria-label="{{ $t('availability') }}">
        @foreach ($chips as $key => $c)
            <a href="{{ $c['url'] }}" class="ls-chip {{ $c['on'] ? 'is-active' : '' }}" @if ($c['on']) aria-current="true" @endif>{{ $c['label'] }}</a>
        @endforeach
    </nav>

    <form method="GET" class="ls-wsd-filters" aria-label="{{ $t('filters') }}">
        @if ($filters['available'] !== null)<input type="hidden" name="available" value="{{ $filters['available'] }}">@endif
        @if ($filters['idle'])<input type="hidden" name="idle" value="1">@endif
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <div class="ls-wsd-filter-row">
            <div class="ls-field ls-filter-field ls-wsd-search">
                <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
                <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $t('search_rooms') }}" autocomplete="off"></div>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-owner">{{ $t('col.workspace') }}</label>
                <select id="f-owner" name="owner" class="ls-select" onchange="this.form.location && (this.form.location.value = ''); this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                    <option value="">{{ $t('all_workspaces') }}</option>
                    @foreach ($owners as $o)<option value="{{ $o->id }}" @selected($filters['owner'] === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
                </select>
            </div>
            @if ($locations->isNotEmpty())
                <div class="ls-field ls-filter-field">
                    <label class="ls-label" for="f-loc">{{ $t('col.location') }}</label>
                    <select id="f-loc" name="location" class="ls-select">
                        <option value="">{{ $t('all_locations') }}</option>
                        @foreach ($locations as $l)<option value="{{ $l->id }}" @selected($filters['location'] === $l->id)>{{ $l->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-type">{{ $t('col.type') }}</label>
                <select id="f-type" name="type" class="ls-select">
                    <option value="">{{ __('app.common.all') }}</option>
                    @foreach (\App\Http\Controllers\Admin\RoomController::TYPES as $ty)<option value="{{ $ty }}" @selected($filters['type'] === $ty)>{{ __('app.room_type.'.$ty) }}</option>@endforeach
                </select>
            </div>
            @include('admin.partials.period', $range)
            <div class="ls-filter-actions">
                <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
                @if ($hasFilters)<a href="/admin/rooms" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
            </div>
        </div>
    </form>

    @if ($rooms->isEmpty())
        <section class="ls-card">
            <x-ui.empty-state :title="$hasFilters ? $t('no_match') : $t('no_rooms')" :text="$hasFilters ? $t('no_match_text') : null">
                @if ($hasFilters)<a href="/admin/rooms" class="ls-btn ls-btn--secondary">{{ $t('reset') }}</a>@endif
            </x-ui.empty-state>
        </section>
    @else
        <div class="ls-wsd-bar">
            <span class="ls-faint">{{ $t('showing', ['from' => $rooms->firstItem(), 'to' => $rooms->lastItem(), 'total' => $rooms->total()]) }}</span>
            <label class="ls-wsd-sort">
                <span>{{ __('app.workspace.sort_by') }}</span>
                <select class="ls-select" onchange="location.href = this.value">
                    @foreach ($sortOptions as $key => $url)
                        <option value="{{ $url }}" @selected($currentSort === $key)>{{ $t('room_sort.'.$key) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        {{-- Same read-only room card as Business → Rooms, plus the business it belongs to. --}}
        <div class="ls-rooms-grid">
            @foreach ($rooms as $room)
                @php $images = $room->images; @endphp
                <article class="ls-room is-{{ $room->is_available ? 'available' : 'unavailable' }}" aria-labelledby="adm-room-{{ $room->id }}">
                    <div class="ls-room-photo" @if ($images->count() > 1) data-ls-gallery @endif>
                        @if ($images->isEmpty())
                            <div class="ls-room-photo-placeholder"><x-ui.icon name="image" /><span>{{ __('app.workspace.no_photos') }}</span></div>
                        @else
                            <div class="ls-room-photo-track">
                                @foreach ($images as $image)
                                    <a href="/admin/rooms/{{ $room->id }}" class="ls-room-photo-slide" tabindex="-1" aria-hidden="true"><img src="{{ $image->thumbnailUrl() }}" loading="lazy" alt=""></a>
                                @endforeach
                            </div>
                            @if ($images->count() > 1)
                                <button type="button" class="ls-room-nav is-prev" aria-label="{{ __('app.workspace.previous_photo') }}"><x-ui.icon name="chevron-right" /></button>
                                <button type="button" class="ls-room-nav is-next" aria-label="{{ __('app.workspace.next_photo') }}"><x-ui.icon name="chevron-right" /></button>
                                <div class="ls-room-dots">
                                    @foreach ($images as $i => $image)
                                        <button type="button" class="ls-room-dot {{ $i === 0 ? 'is-active' : '' }}" aria-label="{{ __('app.workspace.photo_of', ['current' => $i + 1, 'total' => $images->count()]) }}"></button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        <span class="ls-room-badge is-{{ $room->is_available ? 'available' : 'unavailable' }}">
                            <span class="ls-dot" aria-hidden="true"></span>{{ $room->is_available ? $t('available') : $t('unavailable') }}
                        </span>
                    </div>

                    <div class="ls-room-body">
                        <div class="ls-room-head">
                            <a href="/admin/rooms/{{ $room->id }}" class="ls-room-name" id="adm-room-{{ $room->id }}" title="{{ $room->name }}">{{ $room->name }}</a>
                        </div>
                        @if ($room->owner)
                            <a href="/admin/owners/{{ $room->owner_id }}" class="ls-adm-room-biz"><x-ui.icon name="building" /><span>{{ $room->owner->business_name ?: $room->owner->name }}</span></a>
                        @endif
                        <div class="ls-room-meta">
                            <span class="ls-room-type is-{{ $room->type }}">{{ $room->typeLabel() }}</span>
                            <span><x-ui.icon name="users" />{{ trans_choice('app.workspace.seats_count', $room->capacity, ['count' => $room->capacity]) }}</span>
                            @if ($room->workspace)<span><x-ui.icon name="pin" />{{ $room->workspace->name }}</span>@endif
                        </div>
                        <div class="ls-room-price"><span class="ls-num">{{ $room->pricingSummary() }}</span></div>

                        <dl class="ls-adm-room-stats">
                            <div><dt>{{ $t('col.bookings_period') }}</dt><dd class="ls-num">{{ number_format($room->period_bookings) }}</dd></div>
                            <div><dt>{{ $t('col.earnings_period') }}</dt><dd class="ls-num {{ (float) $room->period_earnings > 0 ? 'is-money' : '' }}">{{ Money::format((float) $room->period_earnings) }}</dd></div>
                        </dl>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="ls-adm-pager">
            <span class="ls-faint">{{ $t('money_note_period') }}</span>
            {{ $rooms->links('admin.partials.pager') }}
        </div>
    @endif
</div>
@endsection
