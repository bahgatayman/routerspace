{{--
    Multiple uses of one physical room, on the room editor. A use says which
    physical room it belongs to; a physical room lists its other uses with
    "Add another use". Availability is shared across all of them — said in
    plain words, no technical terms.
--}}
@php
    $physical = $room->isAdditionalUse() ? $room->physicalRoom : $room;
    $uses = $physical?->uses()->get() ?? collect();
@endphp

@if ($physical)
    <section class="ls-room-uses-panel" aria-labelledby="room-uses-title" style="margin-bottom: var(--space-5)">
        @if ($room->isAdditionalUse())
            <x-ui.banner tone="info">
                {{ __('app.workspace.uses.this_is_a_use', ['room' => $physical->name]) }}
                <a href="{{ route('rooms.edit', [$workspace, $physical]) }}" class="ls-link">{{ __('app.workspace.uses.open_room', ['room' => $physical->name]) }}</a>
            </x-ui.banner>
        @else
            <h2 class="ls-card-title" id="room-uses-title" style="margin-bottom: var(--space-2)">{{ __('app.workspace.uses.title') }}</h2>
            <p class="ls-hint" style="margin: 0 0 var(--space-3)">{{ __('app.workspace.uses.shared_availability_hint') }}</p>

            @if ($uses->isNotEmpty())
                <ul class="ls-room-uses">
                    @foreach ($uses as $use)
                        <li>
                            <a href="{{ route('rooms.edit', [$workspace, $use]) }}" class="ls-room-use-name">{{ $use->name }}</a>
                            <span class="ls-faint">{{ $use->typeLabel() }} · <span class="ls-num">{{ $use->pricingSummary() }}</span></span>
                            @if ($use->is_available)
                                <span class="ls-status"><span class="ls-dot" aria-hidden="true"></span>{{ __('app.workspace.uses.active') }}</span>
                            @else
                                <x-ui.badge tone="neutral" :dot="false">{{ __('app.workspace.uses.inactive') }}</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-ui.button size="sm" variant="tonal" icon="plus" :href="route('rooms.uses.create', [$workspace, $room])">{{ __('app.workspace.uses.add') }}</x-ui.button>
        @endif
    </section>
@endif
