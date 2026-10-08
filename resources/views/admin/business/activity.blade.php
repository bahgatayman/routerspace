@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.activity'))

@php $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r); @endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'activity'])

    <nav class="ls-chips" aria-label="{{ $t('actor') }}">
        @foreach ([null => __('app.common.all'), 'owner' => __('app.admin_biz.owner'), 'staff' => __('app.admin_biz.staff_member')] as $k => $label)
            <a href="{{ request()->fullUrlWithQuery(['actor' => $k ?: null, 'page' => null]) }}" class="ls-chip {{ $actor === ($k ?: null) ? 'is-active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <section class="ls-card">
        <div class="ls-card-body">
            @if ($logs->isEmpty())
                <x-ui.empty-state :title="__('app.admin_biz.no_activity')" />
            @else
                <ol class="ls-biz-feed">
                    @foreach ($logs as $l)
                        <li class="is-{{ $l->actor_type ?? 'staff' }}">
                            <time datetime="{{ $l->created_at?->toIso8601String() }}">{{ $l->created_at?->translatedFormat('M j, Y · g:i A') }}</time>
                            <span><b>{{ $l->actor_name ?: ($l->actor_type === 'owner' ? __('app.admin_biz.owner') : __('app.admin_biz.staff_member')) }}</b> — {{ $l->description ?: $l->action }}</span>
                            @if ($l->subject_type === \App\Models\Booking::class && $l->subject_id)<a href="/admin/bookings/{{ $l->subject_id }}" class="ls-link">{{ __('app.admin_biz.open') }}</a>@else<span></span>@endif
                        </li>
                    @endforeach
                </ol>
                <div class="ls-adm-pager" style="padding-inline:0"><span></span>{{ $logs->links('admin.partials.pager') }}</div>
            @endif
        </div>
    </section>
</div>
@endsection
