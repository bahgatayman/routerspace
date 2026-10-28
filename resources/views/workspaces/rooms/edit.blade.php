@extends('layouts.app')

{{--
    Edit Room: the same three-step wizard as Add Room (_wizard.blade.php),
    every step open from the start and "Save changes" on each, as one PUT to
    rooms.update with the same fields as before. Room Photos stays its own
    card (and own uploads) below the form.
--}}
@section('page-title', __('app.workspace.edit_room'))

@section('content')
    @php $cancelUrl = route('workspaces.index', ['workspace' => $workspace->id]); @endphp
    <div class="ls-wizard-page">
        <nav class="ls-wizard-crumbs" aria-label="{{ __('app.workspace.wizard.breadcrumb') }}">
            <a href="{{ $cancelUrl }}" class="ls-link">{{ $workspace->name }}</a>
            <span aria-hidden="true">/</span>
            <span>{{ $room->name }}</span>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ __('app.workspace.edit_room') }}</span>
        </nav>

        <x-ui.page-header :title="__('app.workspace.edit_room')" :subtitle="__('app.workspace.wizard.edit_subtitle')" />

        <x-ui.flash />

        @if ($errors->any())
            <div class="ls-wizard-note">
                <x-ui.banner tone="danger">{{ __('app.workspace.wizard.fix_errors') }}</x-ui.banner>
            </div>
        @endif

        <div class="ls-card ls-wizard-uses">
            <div class="ls-card-body">
                @include('workspaces.rooms.uses._panel')
            </div>
        </div>

        @include('workspaces.rooms._wizard', [
            'room' => $room,
            'physicalRoom' => null,
            'action' => route('rooms.update', [$workspace, $room]),
            'method' => 'PUT',
            'submitLabel' => __('app.workspace.wizard.save_changes'),
            'busyLabel' => __('app.workspace.wizard.saving'),
            'cancelUrl' => $cancelUrl,
        ])

        {{--
            Room Photos — a separate card (and separate <form>, since this posts
            to its own upload endpoint, not rooms.update).
        --}}
        @include('workspaces._photo-manager', [
            'anchor' => 'room-photos',
            'title' => __('app.workspace.room_photos'),
            'hint' => __('app.workspace.room_photos_hint'),
            'emptyText' => __('app.workspace.room_photos_empty'),
            'coverLabel' => __('app.workspace.cover_photo'),
            'setCoverLabel' => __('app.workspace.set_as_cover'),
            'images' => $room->images->map(fn ($image) => [
                'id' => $image->id,
                'thumb' => $image->thumbnailUrl(),
                'cover' => $image->is_cover,
                'coverUrl' => route('rooms.images.cover', [$workspace, $room, $image]),
                'deleteUrl' => route('rooms.images.destroy', [$workspace, $room, $image]),
            ])->all(),
            'reorderUrl' => route('rooms.images.reorder', [$workspace, $room]),
            'storeUrl' => route('rooms.images.store', [$workspace, $room]),
        ])
    </div>
@endsection
