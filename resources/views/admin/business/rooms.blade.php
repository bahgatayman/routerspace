@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.rooms'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'rooms', 'locationAware' => true])

    <form method="GET" class="ls-adm-filters">
        @if ($workspace)<input type="hidden" name="workspace" value="{{ $workspace->id }}">@endif
        @include('admin.partials.period', $range)
        <div class="ls-filter-actions"><button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button></div>
    </form>

    @if ($rooms->isEmpty())
        <section class="ls-card"><x-ui.empty-state :title="$t('no_rooms')" /></section>
    @else
        {{-- Read-only cards: the room's photos (swipeable when there are several),
             then what it is and how it performed in the selected period. --}}
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
                        <div class="ls-room-meta">
                            <span class="ls-room-type is-{{ $room->type }}">{{ $room->typeLabel() }}</span>
                            <span><x-ui.icon name="users" />{{ trans_choice('app.workspace.seats_count', $room->capacity, ['count' => $room->capacity]) }}</span>
                            @if ($room->workspace)<span><x-ui.icon name="pin" />{{ $room->workspace->name }}</span>@endif
                        </div>
                        <div class="ls-room-price"><span class="ls-num">{{ $room->pricingSummary() }}</span></div>

                        <dl class="ls-adm-room-stats">
                            <div><dt>{{ $t('col.bookings_period') }}</dt><dd class="ls-num">{{ number_format($room->period_bookings) }}</dd></div>
                            <div><dt>{{ $t('hours_billed') }}</dt><dd class="ls-num">{{ number_format((float) $room->period_hours, 1) }}</dd></div>
                            <div><dt>{{ $t('col.earnings_period') }}</dt><dd class="ls-num is-money">{{ Money::format((float) $room->period_earnings) }}</dd></div>
                        </dl>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
