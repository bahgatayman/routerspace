@extends('layouts.admin')

@section('page-title', __('app.admin_biz.tabs.audit').' · '.($owner->business_name ?: $owner->name))

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'audit'])

    <section class="ls-card ls-biz-panel">
        <div class="ls-card-head"><h2 class="ls-card-title">{{ __('app.admin_biz.audit_title') }}</h2></div>
        @if ($logs->isEmpty())
            <x-ui.empty-state :title="__('app.admin_biz.audit_empty')" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th>{{ __('app.admin_biz.col_when') }}</th>
                            <th>{{ __('app.admin_biz.col_admin') }}</th>
                            <th>{{ __('app.admin_biz.col_action') }}</th>
                            <th>{{ __('app.admin_biz.col_reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="ls-nowrap">{{ $log->created_at->translatedFormat('M j, Y · g:i A') }}</td>
                                <td>{{ $log->admin_name ?? '—' }}</td>
                                <td>{{ $log->description ?? $log->action }}</td>
                                <td>{{ $log->reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ls-biz-pager">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
@endsection
