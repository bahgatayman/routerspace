@extends('layouts.app')

@section('page-title', __('app.section.staff'))

@section('content')
    {{--
        Staff as employee badges: badge no. + status → initials → name → role
        → email / access / last sign-in → Edit, Permissions and a ⋯ menu
        (manage permissions, activity, disable). Search + status filters are server-side so
        they work across pages. Same routes and forms as before.
    --}}
    <x-ui.page-header :title="__('app.section.staff')" :count="$counts['all']" :subtitle="__('app.staff.subtitle')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" href="/staff/create">{{ __('app.staff.add_staff') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    @if ($counts['all'] > 0)
        <div class="ls-toolbar">
            <nav class="ls-chips" aria-label="{{ __('app.table.th.status') }}">
                @foreach (['all' => null, 'active' => 'active', 'disabled' => 'disabled'] as $key => $value)
                    @php $isOn = $status === $value; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['status' => $value, 'page' => null]) }}"
                       class="ls-chip {{ $isOn ? 'is-active' : '' }}" @if ($isOn) aria-current="page" @endif>
                        {{ __('app.staff.filter_'.$key) }} <span class="ls-chip-count">{{ $counts[$key] }}</span>
                    </a>
                @endforeach
            </nav>
            <span class="ls-toolbar-spacer"></span>
            <form method="GET" action="/staff" class="ls-search ls-staff-search" role="search">
                @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <x-ui.icon name="search" />
                <input type="search" name="search" value="{{ $search }}" class="ls-input" placeholder="{{ __('app.staff.search') }}" aria-label="{{ __('app.staff.search') }}" autocomplete="off">
            </form>
        </div>
    @endif

    @if ($staff->count() > 0)
        <div class="ls-staff-grid">
            @foreach ($staff as $member)
                @php
                    $roleLabel = $member->role ? __('app.role.'.$member->role->key) : __('app.staff.custom_permissions');
                    $menuId = 'staff-menu-'.$member->id;
                @endphp
                {{-- Employee badge: lanyard band (badge no. + status) → photo/initials → name → role → compact facts → actions. --}}
                <article class="ls-staff {{ $member->is_active ? '' : 'is-disabled' }}" aria-labelledby="staff-{{ $member->id }}-name">
                    <div class="ls-staff-band">
                        <span class="ls-staff-no">{{ __('app.staff.badge_id') }} <bdi dir="ltr" class="ls-num">#{{ str_pad((string) $member->id, 4, '0', STR_PAD_LEFT) }}</bdi></span>
                        <span class="ls-staff-slot" aria-hidden="true"></span>
                        @if ($member->is_active)
                            <span class="ls-staff-state is-on"><span class="ls-dot"></span>{{ __('app.status.active') }}</span>
                        @else
                            <span class="ls-staff-state"><span class="ls-dot"></span>{{ __('app.staff.disabled') }}</span>
                        @endif
                    </div>

                    <div class="ls-staff-id">
                        <x-ui.avatar :name="$member->name" size="lg" class="ls-staff-photo" />
                        <a href="/staff/{{ $member->id }}/edit" class="ls-staff-name" id="staff-{{ $member->id }}-name" title="{{ $member->name }}">{{ $member->name }}</a>
                        <span class="ls-staff-role {{ $member->role ? '' : 'is-custom' }}">{{ $roleLabel }}</span>
                    </div>

                    <dl class="ls-staff-facts">
                        <div class="is-wide">
                            <dt>{{ __('app.staff.email') }}</dt>
                            <dd><bdi dir="ltr" class="ls-staff-email" title="{{ $member->email }}">{{ $member->email }}</bdi></dd>
                        </div>
                        <div>
                            <dt>{{ __('app.staff.access') }}</dt>
                            <dd class="{{ $member->permissions_count ? '' : 'ls-faint' }}">{{ trans_choice('app.staff.permissions_count', $member->permissions_count, ['count' => $member->permissions_count]) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('app.staff.last_login') }}</dt>
                            <dd class="{{ $member->last_login_at ? '' : 'ls-faint' }}"
                                @if ($member->last_login_at) title="{{ $member->last_login_at->format('M d, Y H:i') }}" @endif>
                                {{ $member->last_login_at ? $member->last_login_at->diffForHumans() : __('app.staff.never_logged_in') }}
                            </dd>
                        </div>
                    </dl>

                    <div class="ls-staff-foot">
                        <x-ui.button size="sm" icon="edit" href="/staff/{{ $member->id }}/edit">{{ __('app.common.edit') }}</x-ui.button>
                        <x-ui.button variant="ghost" size="sm" icon="lock" href="/staff/{{ $member->id }}/edit#permissions">{{ __('app.staff.permissions') }}</x-ui.button>
                        <div class="ls-menu-wrap ls-staff-more">
                            <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm ls-btn--icon" data-ls-menu="{{ $menuId }}" aria-haspopup="true" aria-expanded="false"
                                    aria-label="{{ __('app.staff.more_actions') }}: {{ $member->name }}" title="{{ __('app.staff.more_actions') }}"><x-ui.icon name="more" /></button>
                            <div id="{{ $menuId }}" class="ls-pop" role="menu" hidden>
                                <div class="ls-menu">
                                    <a href="/staff/{{ $member->id }}/edit#permissions" class="ls-menu-item" role="menuitem"><x-ui.icon name="lock" /><span>{{ __('app.staff.manage_permissions') }}</span></a>
                                    <a href="/staff/{{ $member->id }}/activity" class="ls-menu-item" role="menuitem"><x-ui.icon name="clock" /><span>{{ __('app.staff.view_activity') }}</span></a>
                                    <form method="POST" action="/staff/{{ $member->id }}/toggle-status"
                                          @if ($member->is_active) onsubmit="return confirm(@js(__('app.staff.confirm_disable')))" @endif>
                                        @csrf
                                        <button type="submit" class="ls-menu-item {{ $member->is_active ? 'is-danger' : '' }}" role="menuitem"><x-ui.icon name="power" />
                                            <span>{{ $member->is_active ? __('app.staff.disable') : __('app.staff.enable') }}</span></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-6">{{ $staff->withQueryString()->links() }}</div>
    @elseif ($search || $status)
        <div class="ls-card">
            <x-ui.empty-state illustration="search" :title="$search ? __('app.staff.no_match', ['q' => $search]) : __('app.staff.no_staff')" :text="__('app.staff.no_match_hint')">
                <x-ui.button href="/staff">{{ __('app.staff.clear_search') }}</x-ui.button>
            </x-ui.empty-state>
        </div>
    @else
        <div class="ls-card">
            <x-ui.empty-state illustration="people" :title="__('app.staff.no_staff')" :text="__('app.staff.empty_hint')">
                <x-ui.button variant="primary" icon="plus" href="/staff/create">{{ __('app.staff.add_staff') }}</x-ui.button>
            </x-ui.empty-state>
        </div>
    @endif
@endsection
