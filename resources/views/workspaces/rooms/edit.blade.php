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
        <div class="ls-card mt-6" id="room-photos" data-ls-photo-manager style="scroll-margin-top: var(--space-6)">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ __('app.workspace.room_photos') }}</h2></div>
            <div class="ls-card-body">
                <p class="ls-hint" style="margin-bottom: var(--space-4)">{{ __('app.workspace.room_photos_hint') }}</p>

                @if ($room->images->isNotEmpty())
                    <div class="ls-room-gallery-manager" data-ls-room-images
                         data-reorder-url="{{ route('rooms.images.reorder', [$workspace, $room]) }}">
                        @foreach ($room->images as $image)
                            <div class="ls-room-image" draggable="true" data-image-id="{{ $image->id }}">
                                <img src="{{ $image->thumbnailUrl() }}" alt="" loading="lazy">
                                @if ($image->is_cover)
                                    <span class="ls-room-image-cover-tag">{{ __('app.workspace.cover_photo') }}</span>
                                @endif
                                <div class="ls-room-image-actions">
                                    @unless ($image->is_cover)
                                        <button type="button" data-ls-set-cover="{{ route('rooms.images.cover', [$workspace, $room, $image]) }}">{{ __('app.workspace.set_as_cover') }}</button>
                                    @endunless
                                    <button type="button" class="is-danger" data-ls-remove-image="{{ route('rooms.images.destroy', [$workspace, $room, $image]) }}"
                                            data-confirm="{{ __('app.workspace.remove_photo') }}?">{{ __('app.common.delete') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="ls-hint" style="margin-top: var(--space-2)">{{ __('app.workspace.reorder_photos_hint') }}</p>
                @else
                    <p class="ls-faint">{{ __('app.workspace.room_photos_empty') }}</p>
                @endif

                <form method="POST" action="{{ route('rooms.images.store', [$workspace, $room]) }}" enctype="multipart/form-data" style="margin-top: var(--space-5)">
                    @csrf
                    <div class="ls-room-dropzone" data-ls-dropzone>
                        <x-ui.icon name="upload" />
                        <span>{{ __('app.workspace.drop_photos_hint') }}</span>
                        <input type="file" name="images[]" multiple accept="image/jpeg,image/jpg,image/png,image/webp">
                    </div>
                    <div class="ls-room-pending" data-ls-pending></div>
                    @error('images') <p class="ls-error" style="margin-top: var(--space-2)">{{ $message }}</p> @enderror
                    @error('images.*') <p class="ls-error" style="margin-top: var(--space-2)">{{ $message }}</p> @enderror
                    <div style="margin-top: var(--space-4)">
                        <x-ui.button type="submit" variant="primary" icon="plus" data-ls-upload-submit disabled>
                            {{ __('app.workspace.add_photos') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
