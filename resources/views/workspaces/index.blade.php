@extends('layouts.app')

@section('page-title', __('app.workspace.rooms_title'))

@section('content')
    {{--
        Rooms are the primary object here, the workspace is just context: one
        workspace skips straight to its rooms, several get a lightweight chip
        selector, zero get the "create your workspace" empty state as the
        whole page. Occupancy/pricing/status are never computed here — they
        arrive pre-computed from WorkspaceController (AvailabilityService,
        RoomPricingService, Room::statusKey()).
    --}}
    <x-ui.page-header :title="__('app.workspace.rooms_title')"
        :subtitle="$activeWorkspace ? __('app.workspace.rooms_subtitle', ['workspace' => $activeWorkspace->name]) : null"
        :count="$roomStats['total'] ?? null">
        <x-slot:actions>
            @if ($activeWorkspace && $canManage)
                <x-ui.button variant="primary" icon="plus" :href="route('rooms.create', $activeWorkspace)">
                    {{ __('app.workspace.add_room') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    @if ($workspaces->count() > 1)
        <nav class="ls-chips is-scroll" style="margin-bottom: var(--space-5)" aria-label="{{ __('app.workspace.my_workspaces') }}">
            @foreach ($workspaces as $ws)
                @php $onWs = $activeWorkspace->id === $ws->id; @endphp
                <a href="{{ route('workspaces.index', ['workspace' => $ws->id]) }}"
                   class="ls-chip {{ $onWs ? 'is-active' : '' }}"
                   data-ls-remember-workspace="{{ $ws->id }}"
                   @if ($onWs) aria-current="page" @endif>
                    {{ $ws->name }} <span class="ls-chip-count">{{ $ws->rooms_count }}</span>
                </a>
            @endforeach
        </nav>
        <script>
            (function () {
                var KEY = 'ls.lastWorkspaceId';
                var params = new URLSearchParams(location.search);
                if (!params.has('workspace')) {
                    var saved = localStorage.getItem(KEY);
                    var ids = @json($workspaces->pluck('id'));
                    var savedId = saved ? parseInt(saved, 10) : null;
                    if (savedId && ids.includes(savedId) && savedId !== {{ $activeWorkspace->id }}) {
                        params.set('workspace', String(savedId));
                        location.replace(location.pathname + '?' + params.toString());
                        return;
                    }
                }
                document.addEventListener('click', function (e) {
                    var chip = e.target.closest('[data-ls-remember-workspace]');
                    if (chip) localStorage.setItem(KEY, chip.dataset.lsRememberWorkspace);
                });
            })();
        </script>
    @endif

    @if ($workspaces->isEmpty())
        {{-- The only case where creating a workspace is the page's primary content. --}}
        <div class="ls-card">
            <x-ui.empty-state illustration="box" :title="__('app.workspace.create_workspace')" :text="__('app.empty.no_workspaces')">
                @if ($canManage)
                    <x-ui.button variant="primary" icon="plus" :href="route('workspaces.create')">
                        {{ __('app.btn.create_workspace') }}
                    </x-ui.button>
                @endif
            </x-ui.empty-state>
        </div>
    @else
        @php
            $status = in_array(request('status'), ['available', 'occupied', 'unavailable'], true) ? request('status') : null;
            $filtered = request('type') || $status;
            $statusTiles = [
                null => ['label' => __('app.workspace.all_rooms'), 'value' => $roomStats['total'], 'tone' => 'is-brand'],
                'available' => ['label' => __('app.workspace.available'), 'value' => $roomStats['available'], 'tone' => ''],
                'occupied' => ['label' => __('app.workspace.occupied'), 'value' => $roomStats['occupied'], 'tone' => ''],
                'unavailable' => ['label' => __('app.workspace.unavailable'), 'value' => $roomStats['unavailable'], 'tone' => $roomStats['unavailable'] ? 'is-warn' : ''],
            ];
        @endphp

        {{-- The stat strip doubles as the status filter: each count is the tab
             that shows those rooms, so status is never shown twice on the page. --}}
        <nav class="ls-strip ls-strip--tabs" aria-label="{{ __('app.workspace.filter_by_status') }}">
            @foreach ($statusTiles as $key => $tile)
                @php $on = $status === ($key ?: null); @endphp
                <a href="{{ request()->fullUrlWithQuery(['status' => $key ?: null]) }}" class="{{ $on ? 'is-active' : '' }}" @if ($on) aria-current="page" @endif>
                    <span class="ls-strip-label">{{ $tile['label'] }}</span>
                    <span class="ls-strip-value {{ $tile['tone'] }}">{{ $tile['value'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="ls-toolbar ls-rooms-toolbar">
            <nav class="ls-chips is-scroll" aria-label="{{ __('app.workspace.type') }}">
                <a href="{{ request()->fullUrlWithQuery(['type' => null]) }}" class="ls-chip {{ ! request('type') ? 'is-active' : '' }}" @unless (request('type')) aria-current="page" @endunless>
                    {{ __('app.inventory.filter_all') }}
                </a>
                @foreach (['meeting', 'training', 'shared', 'office', 'studio'] as $t)
                    <a href="{{ request()->fullUrlWithQuery(['type' => $t]) }}" class="ls-chip {{ request('type') === $t ? 'is-active' : '' }}" @if (request('type') === $t) aria-current="page" @endif>
                        {{ __('app.room_type.'.$t) }}
                    </a>
                @endforeach
            </nav>
            <div class="ls-toolbar-spacer"></div>
            <x-ui.search group="rooms" :placeholder="__('app.ui.search_rooms')" />
            <form method="GET" class="ls-rooms-sort">
                <input type="hidden" name="workspace" value="{{ $activeWorkspace->id }}">
                @if (request('type')) <input type="hidden" name="type" value="{{ request('type') }}"> @endif
                @if ($status) <input type="hidden" name="status" value="{{ $status }}"> @endif
                <select name="sort" class="ls-select" aria-label="{{ __('app.workspace.sort_by') }}" title="{{ __('app.workspace.sort_by') }}"
                        onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                    <option value="name" @selected(! request('sort') || request('sort') === 'name')>{{ __('app.workspace.sort_name') }}</option>
                    <option value="price" @selected(request('sort') === 'price')>{{ __('app.workspace.sort_price') }}</option>
                    <option value="availability" @selected(request('sort') === 'availability')>{{ __('app.workspace.sort_availability') }}</option>
                </select>
            </form>
        </div>

        @if ($rooms->isEmpty())
            <div class="ls-card">
                @if ($filtered)
                    <x-ui.empty-state illustration="search" :title="__('app.ui.no_rooms_match_title')" :text="__('app.ui.no_rooms_match_text')">
                        <x-ui.button :href="route('workspaces.index', ['workspace' => $activeWorkspace->id])">{{ __('app.workspace.clear_filters') }}</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state illustration="box" :title="__('app.empty.no_rooms')" :text="__('app.workspace.no_rooms_hint')">
                        @if ($canManage)
                            <x-ui.button variant="primary" icon="plus" :href="route('rooms.create', $activeWorkspace)">
                                {{ __('app.workspace.add_room') }}
                            </x-ui.button>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            <div class="ls-rooms-grid">
                @foreach ($rooms as $room)
                    @include('workspaces.rooms._card', ['workspace' => $activeWorkspace, 'room' => $room, 'canManage' => $canManage])
                @endforeach
            </div>
            <div class="ls-card" data-ls-empty="rooms" hidden>
                <x-ui.empty-state illustration="search" :title="__('app.ui.no_rooms_match_title')" :text="__('app.ui.no_rooms_match_text')" />
            </div>
        @endif
    @endif
@endsection
