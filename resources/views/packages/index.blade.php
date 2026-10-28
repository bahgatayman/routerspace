@extends('layouts.app')

@section('page-title', __('app.packages.title'))

@section('content')
    {{--
        Hour Package templates as cards: name, hours and price first, then
        validity and rooms. Assigning happens from the member's profile.
    --}}
    <x-ui.page-header :title="__('app.packages.title')" :subtitle="__('app.packages.subtitle')" :count="$templates->count() ?: null">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" data-ls-open="create-package-modal">{{ __('app.packages.new_template') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    @if ($templates->isEmpty())
        <div class="ls-card">
            <x-ui.empty-state illustration="quiet" :title="__('app.packages.empty_title')" :text="__('app.packages.empty_body')">
                @if ($canManage)
                    <x-ui.button variant="primary" icon="plus" data-ls-open="create-package-modal">{{ __('app.packages.new_template') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        </div>
    @else
        <div class="ls-product-grid">
            @foreach ($templates as $t)
                @php
                    $hours = $t->total_minutes / 60;
                    $perHour = $hours > 0 ? (float) $t->price / $hours : 0;
                    $rooms = $t->room_ids ? collect($t->room_ids)->map(fn ($id) => $roomNames[$id] ?? null)->filter() : null;
                @endphp
                <article class="ls-product ls-pkg-tpl {{ $t->is_active ? '' : 'is-inactive' }}" id="package-template-{{ $t->id }}" aria-labelledby="pkg-{{ $t->id }}-name">
                    <div class="ls-product-top">
                        <span class="ls-product-icon is-product" aria-hidden="true"><x-ui.icon name="clock" /></span>
                        @if ($t->is_active)
                            <span class="ls-status"><span class="ls-dot"></span>{{ __('app.packages.active') }}</span>
                        @else
                            <x-ui.badge tone="neutral" :dot="false">{{ __('app.packages.inactive') }}</x-ui.badge>
                        @endif
                    </div>

                    <div class="ls-product-body">
                        <h3 class="ls-product-name ls-trunc" id="pkg-{{ $t->id }}-name" title="{{ $t->name }}">{{ $t->name }}</h3>
                        <div class="ls-product-meta">
                            <span>{{ $t->hoursLabel() }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ __('app.packages.validity_label', ['days' => $t->validity_days]) }}</span>
                        </div>
                    </div>

                    <div class="ls-product-price"><x-ui.money :amount="$t->price" /></div>

                    <dl class="ls-inv-facts">
                        <div><dt>{{ __('app.packages.rate') }}</dt><dd class="ls-num"><x-ui.money :amount="round($perHour, 2)" /></dd></div>
                        <div><dt>{{ __('app.packages.rooms') }}</dt>
                            <dd class="ls-trunc" title="{{ $rooms ? $rooms->implode(', ') : __('app.packages.all_rooms') }}">{{ $rooms ? $rooms->implode(', ') : __('app.packages.all_rooms') }}</dd></div>
                        <div><dt>{{ __('app.packages.sold') }}</dt>
                            <dd>{{ trans_choice('app.packages.sold_count', $t->member_packages_count, ['count' => $t->member_packages_count]) }}</dd></div>
                    </dl>

                    @if ($canManage)
                        <div class="ls-product-foot">
                            <x-ui.button size="sm" :href="route('packages.edit', $t->id)">{{ __('app.packages.edit') }}</x-ui.button>
                            <form method="POST" action="{{ route('packages.toggle', $t->id) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="sm">{{ $t->is_active ? __('app.packages.deactivate') : __('app.packages.activate') }}</x-ui.button>
                            </form>
                            @if ($t->member_packages_count === 0)
                                <form method="POST" action="{{ route('packages.destroy', $t->id) }}" class="ls-product-delete"
                                      onsubmit="return confirm(@js(__('app.packages.confirm_delete')))">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger-quiet" size="sm" icon="trash" :icon-only="true"
                                        :aria-label="__('app.packages.delete').': '.$t->name" :title="__('app.packages.delete')" />
                                </form>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if ($canManage)
        {{-- Was a standalone page (packages/create); now a modal here so adding
             a template never leaves this list. Plain form POST to the existing
             packages.store route — unchanged server-side, including its
             redirect back to packages.index on both success and validation
             failure, which is exactly this page. --}}
        <x-ui.modal id="create-package-modal" :title="__('app.packages.create_title')" size="wide">
            <form id="create-package-form" method="POST" action="{{ route('packages.store') }}">
                @csrf
                @include('packages._form', ['template' => $template, 'roomGroups' => $roomGroups])
            </form>

            <x-slot:footer>
                <x-ui.button variant="ghost" data-ls-close>{{ __('app.packages.cancel') }}</x-ui.button>
                <div class="ls-push">
                    <x-ui.button type="submit" form="create-package-form" variant="primary">{{ __('app.packages.save') }}</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

        @if ($errors->any() && old('name') !== null)
            <script>document.addEventListener('DOMContentLoaded', () => LS.open('create-package-modal'));</script>
        @endif
    @endif
@endsection
