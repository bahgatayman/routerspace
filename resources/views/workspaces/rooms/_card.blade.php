@php
    $statusKey = $room->statusKey($room->occupied_seats);
    $images = $room->images; // eager-loaded by WorkspaceController — never queried per card
    $canManage = $canManage ?? true;
    $showUrl = route('rooms.show', [$workspace, $room]);
    $editUrl = route('rooms.edit', [$workspace, $room]);
    $menuId = 'room-menu-'.$room->id;
    // Rooms it shares availability with (another use of the same physical
    // room, or this physical room's uses) — eager-loaded, empty for most rooms.
    $sharesWith = $room->isAdditionalUse()
        ? collect([$room->relationLoaded('physicalRoom') ? $room->physicalRoom : null])->filter()
        : ($room->relationLoaded('uses') ? $room->uses : collect());
    $sharesWithNames = $sharesWith->pluck('name')->implode(__('app.workspace.uses.name_separator'));
    $sharesWithText = $sharesWith->isNotEmpty() ? __('app.workspace.uses.shares_with', ['room' => $sharesWithNames]) : null;
    // Same sentence with the room name(s) set apart, each bidi-isolated (a Latin
    // name inside Arabic text); names are escaped, the sentence is a trusted translation.
    $sharesWithHtml = $sharesWith->isNotEmpty()
        ? __('app.workspace.uses.shares_with', ['room' => '<b class="ls-room-shares-name">'
            .$sharesWith->map(fn ($r) => '<bdi>'.e($r->name).'</bdi>')->implode(e(__('app.workspace.uses.name_separator'))).'</b>'])
        : null;
@endphp
<article class="ls-room is-{{ $statusKey }}" aria-labelledby="room-{{ $room->id }}-name"
         data-ls-item="rooms" data-ls-text="{{ $room->name }} {{ $room->typeLabel() }}{{ $sharesWithText ? ' '.$sharesWithText : '' }}">
    <div class="ls-room-photo" @if ($images->count() > 1) data-ls-gallery @endif>
        @if ($images->isEmpty())
            @if ($canManage)
                <a href="{{ $editUrl }}#room-photos" class="ls-room-photo-placeholder is-action">
                    <x-ui.icon name="image" />
                    <span>{{ __('app.workspace.add_photos_cta') }}</span>
                </a>
            @else
                <div class="ls-room-photo-placeholder"><x-ui.icon name="image" /></div>
            @endif
        @else
            <div class="ls-room-photo-track">
                @foreach ($images as $image)
                    <a href="{{ $showUrl }}" class="ls-room-photo-slide" tabindex="-1" aria-hidden="true"><img src="{{ $image->thumbnailUrl() }}" loading="lazy" alt=""></a>
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

        <span class="ls-room-badge is-{{ $statusKey }}">
            <span class="ls-dot" aria-hidden="true"></span>{{ $room->statusLabel($room->occupied_seats) }}
        </span>
    </div>

    <div class="ls-room-body">
        <div class="ls-room-head">
            <a href="{{ $showUrl }}" class="ls-room-name" id="room-{{ $room->id }}-name" title="{{ $room->name }}">{{ $room->name }}</a>
            @if ($canManage)
                <div class="ls-menu-wrap ls-room-menu">
                    <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm ls-btn--icon" data-ls-menu="{{ $menuId }}" aria-haspopup="true" aria-expanded="false"
                            aria-label="{{ __('app.workspace.more_actions') }}: {{ $room->name }}" title="{{ __('app.workspace.more_actions') }}"><x-ui.icon name="more" /></button>
                    <div id="{{ $menuId }}" class="ls-pop" role="menu" hidden>
                        <div class="ls-menu">
                            <a href="{{ route('rooms.uses.create', [$workspace, $room]) }}" class="ls-menu-item" role="menuitem"><x-ui.icon name="plus" /><span>{{ __('app.workspace.uses.add') }}</span></a>
                            <form method="POST" action="{{ route('rooms.toggle', [$workspace, $room]) }}"
                                  @if ($room->is_available && $sharesWithText) onsubmit="return confirm(@js(__('app.workspace.uses.deactivate_confirm', ['name' => $room->name])))" @endif>
                                @csrf
                                <button type="submit" class="ls-menu-item" role="menuitem"><x-ui.icon name="power" />
                                    <span>{{ $room->is_available ? __('app.workspace.mark_unavailable') : __('app.workspace.mark_available') }}</span></button>
                            </form>
                            <form method="POST" action="{{ route('rooms.destroy', [$workspace, $room]) }}" onsubmit="return confirm(@js(__('app.workspace.delete_room_confirm')))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ls-menu-item is-danger" role="menuitem"><x-ui.icon name="trash" /><span>{{ __('app.common.delete') }}</span></button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="ls-room-meta">
            <span class="ls-room-type is-{{ $room->type }}">{{ $room->typeLabel() }}</span>
            <span><x-ui.icon name="users" />{{ trans_choice('app.workspace.seats_count', $room->capacity, ['count' => $room->capacity]) }}</span>
        </div>

        @if ($sharesWithText)
            <p class="ls-room-shares" title="{{ $sharesWithText }}"><x-ui.icon name="calendar" /><span>{!! $sharesWithHtml !!}</span></p>
        @endif

        @if ($room->isShared() && $room->is_available && $room->effectiveCapacity() > 1)
            @php $cap = max(1, $room->effectiveCapacity()); $used = min($cap, $room->occupied_seats); @endphp
            <div class="ls-room-seats">
                <div class="ls-meter {{ $used >= $cap ? 'is-danger' : ($used / $cap >= .8 ? 'is-warn' : '') }}" role="progressbar"
                     aria-valuemin="0" aria-valuemax="{{ $cap }}" aria-valuenow="{{ $used }}" aria-label="{{ __('app.workspace.seats_in_use', ['used' => $used, 'total' => $cap]) }}">
                    <i style="width: {{ round($used / $cap * 100) }}%"></i>
                </div>
            </div>
        @endif

        <div class="ls-room-price">
            <span class="ls-num">{{ $room->pricingSummary() }}</span>
            @if ($room->plans_count || ($room->pricing_profiles_count ?? 0))
                <span class="ls-room-tags">
                    @if ($room->plans_count)
                        <a href="{{ $editUrl }}#plans-title" class="ls-plan-tag">{{ trans_choice('app.plans.count', $room->plans_count, ['count' => $room->plans_count]) }}</a>
                    @endif
                    @if ($room->pricing_profiles_count ?? 0)
                        <a href="{{ $editUrl }}#profiles-title" class="ls-plan-tag">{{ trans_choice('app.pricing_profiles.count', $room->pricing_profiles_count, ['count' => $room->pricing_profiles_count]) }}</a>
                    @endif
                </span>
            @endif
        </div>
    </div>

    <div class="ls-room-foot">
        @if ($canManage)
            <x-ui.button size="sm" variant="tonal" icon="edit" :href="$editUrl">{{ __('app.common.edit') }}</x-ui.button>
            <x-ui.button size="sm" variant="ghost" :href="$showUrl">{{ __('app.workspace.view_room') }}</x-ui.button>
        @else
            <x-ui.button size="sm" variant="tonal" :href="$showUrl" block>{{ __('app.workspace.view_room') }}</x-ui.button>
        @endif
    </div>
</article>
