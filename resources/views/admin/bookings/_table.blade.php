{{--
    Bookings table (platform list, room detail, business tab).
    Expects $bookings (with owner, room.workspace, hotspotUser loaded); optional $hide = ['workspace', 'room'].
--}}
@php
    use App\Support\Money;
    $hide = $hide ?? [];
    $statusTone = ['completed' => 'ok', 'confirmed' => 'info', 'checked_in' => 'info', 'open' => 'info', 'pending' => 'warn', 'cancelled' => 'danger', 'no_show' => 'neutral'];
    $sortable = isset($sort);
@endphp
@if ($bookings->isEmpty())
    <x-ui.empty-state :title="__('app.admin_platform.no_bookings')" />
@else
    <div class="ls-table-wrap">
        <table class="ls-table ls-adm-table">
            <thead><tr>
                @if ($sortable)@include('admin.partials.sort', ['key' => 'id', 'label' => '#'])@else<th scope="col">#</th>@endif
                @if ($sortable)@include('admin.partials.sort', ['key' => 'date', 'label' => __('app.admin_platform.col.date')])@else<th scope="col">{{ __('app.admin_platform.col.date') }}</th>@endif
                <th scope="col">{{ __('app.admin_platform.col.customer') }}</th>
                @unless (in_array('workspace', $hide))<th scope="col">{{ __('app.admin_platform.col.workspace') }}</th>@endunless
                @unless (in_array('room', $hide))<th scope="col">{{ __('app.admin_platform.col.room') }}</th>@endunless
                <th scope="col">{{ __('app.common.status') }}</th>
                <th scope="col">{{ __('app.admin_platform.col.payment') }}</th>
                @if ($sortable)@include('admin.partials.sort', ['key' => 'amount', 'label' => __('app.admin_platform.col.net'), 'class' => 'is-num'])@else<th scope="col" class="is-num">{{ __('app.admin_platform.col.net') }}</th>@endif
                @if ($sortable)@include('admin.partials.sort', ['key' => 'paid', 'label' => __('app.admin_platform.col.paid'), 'class' => 'is-num'])@else<th scope="col" class="is-num">{{ __('app.admin_platform.col.paid') }}</th>@endif
            </tr></thead>
            <tbody>
                @foreach ($bookings as $b)
                    <tr class="row-link" data-href="/admin/bookings/{{ $b->id }}">
                        <td><a href="/admin/bookings/{{ $b->id }}" class="ls-link ls-num">#{{ $b->id }}</a></td>
                        <td class="ls-nowrap"><span class="ls-num">{{ $b->booking_date?->translatedFormat('M j, Y') }}</span><small class="ls-adm-sub">{{ $b->timeRange() }}</small></td>
                        <td>{{ $b->hotspotUser?->name ?? __('app.admin_biz.deleted_member') }}@if ($b->hotspotUser?->phone)<small class="ls-adm-sub"><bdi dir="ltr">{{ $b->hotspotUser->phone }}</bdi></small>@endif</td>
                        @unless (in_array('workspace', $hide))<td><a href="/admin/owners/{{ $b->owner_id }}" class="ls-link">{{ $b->owner?->business_name ?? '—' }}</a></td>@endunless
                        @unless (in_array('room', $hide))<td>{{ $b->room?->name ?? '—' }}@if ($b->room?->workspace)<small class="ls-adm-sub">{{ $b->room->workspace->name }}</small>@endif</td>@endunless
                        <td>
                            <x-ui.badge :tone="$statusTone[$b->status] ?? 'neutral'">{{ __('app.admin_platform.status.'.$b->status) }}</x-ui.badge>
                            @if ($b->shared_session_exists ?? false)<small class="ls-adm-sub">{{ __('app.admin_platform.types.session') }}</small>@endif
                        </td>
                        <td><x-ui.badge :tone="$b->paymentStatusTone()" :dot="false">{{ $b->paymentStatusLabel() }}</x-ui.badge></td>
                        <td class="is-money">{{ Money::format($b->netRoomCharge()) }}</td>
                        <td class="is-money">{{ Money::format((float) $b->amount_paid) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
