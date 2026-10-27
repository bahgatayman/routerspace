@extends('layouts.app')

{{--
    Add Room — also "Add another use" of an existing room ($physicalRoom set).
    The form itself is the shared three-step wizard (_wizard.blade.php),
    posting to the same endpoint with the same fields as before.
--}}
@php
    $physicalRoom = $physicalRoom ?? null;
    $pageTitle = $physicalRoom ? __('app.workspace.uses.add_title', ['room' => $physicalRoom->name]) : __('app.workspace.add_room');
    $cancelUrl = route('workspaces.index', ['workspace' => $workspace->id]);
@endphp

@section('page-title', $physicalRoom ? __('app.workspace.uses.add') : __('app.workspace.add_room'))

@section('content')
    <div class="ls-wizard-page">
        <nav class="ls-wizard-crumbs" aria-label="{{ __('app.workspace.wizard.breadcrumb') }}">
            <a href="{{ $cancelUrl }}" class="ls-link">{{ $workspace->name }}</a>
            @if ($physicalRoom)
                <span aria-hidden="true">/</span>
                <a href="{{ route('rooms.edit', [$workspace, $physicalRoom]) }}" class="ls-link">{{ $physicalRoom->name }}</a>
            @endif
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $physicalRoom ? __('app.workspace.uses.add') : __('app.workspace.add_room') }}</span>
        </nav>

        <x-ui.page-header :title="$pageTitle"
            :subtitle="$physicalRoom ? __('app.workspace.uses.add_intro') : __('app.workspace.wizard.subtitle')" />

        <x-ui.flash />

        @if ($physicalRoom)
            <div class="ls-wizard-note">
                <x-ui.banner tone="info">{{ __('app.workspace.uses.add_shares_hint', ['room' => $physicalRoom->name]) }}</x-ui.banner>
            </div>
        @endif

        @if ($errors->any())
            <div class="ls-wizard-note">
                <x-ui.banner tone="danger">{{ __('app.workspace.wizard.fix_errors') }}</x-ui.banner>
            </div>
        @endif

        @include('workspaces.rooms._wizard', [
            'room' => null,
            'physicalRoom' => $physicalRoom,
            'action' => $physicalRoom ? route('rooms.uses.store', [$workspace, $physicalRoom]) : route('rooms.store', $workspace),
            'method' => 'POST',
            'submitLabel' => $physicalRoom ? __('app.workspace.uses.add') : __('app.btn.add_room'),
            'busyLabel' => __('app.workspace.wizard.creating'),
            'cancelUrl' => $cancelUrl,
        ])
    </div>
@endsection
