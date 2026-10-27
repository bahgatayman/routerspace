@extends('layouts.app')

@section('page-title', __('app.workspace.edit_room'))

@section('content')
    <div class="max-w-2xl mx-auto">
        <nav class="text-sm text-gray-500 mb-4">
            <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}" class="text-blue-600 hover:text-blue-800">{{ $workspace->name }}</a>
            <span class="mx-2">/</span>
            <span class="text-gray-900">{{ __('app.workspace.edit_room') }}</span>
        </nav>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-6">{{ __('app.workspace.edit_room') }}</h1>

            @include('workspaces.rooms.uses._panel')

            <form method="POST" action="{{ route('rooms.update', [$workspace, $room]) }}">
                @csrf
                @method('PUT')

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.room_name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $room->name) }}" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.type') }} <span class="text-red-500">*</span></label>
                        <select name="type" id="room_type" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            @foreach($roomTypes as $key => $label)
                                <option value="{{ $key }}" {{ old('type', $room->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-500 mt-1" id="room_type_hint">
                            {{ old('type', $room->type) === 'shared' ? __('app.workspace.shared_room_hint') : __('app.workspace.exclusive_room_hint') }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.capacity') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="capacity" min="1" max="999"
                               value="{{ old('capacity', $room->capacity) }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('capacity') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    @include('workspaces.rooms._pricing', ['room' => $room])

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.description') }}</label>
                        <textarea name="description" rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ old('description', $room->description) }}</textarea>
                        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        {{ __('app.common.update') }}
                    </button>
                    <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}"
                        class="text-sm text-gray-600 hover:text-gray-800">{{ __('app.common.cancel') }}</a>
                </div>
            </form>
        </div>

        {{--
            Room Photos — a separate card (and separate <form>, since this posts
            to its own upload endpoint, not rooms.update) deliberately built on
            the ls-*/x-ui.* design system rather than matching the legacy
            Tailwind form above: new UI follows the current system, the
            untouched existing fields above don't need a risky unrelated rewrite.
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

    <script>
        (function () {
            var select = document.getElementById('room_type');
            var hint = document.getElementById('room_type_hint');
            if (!select || !hint) return;
            var sharedHint = @json(__('app.workspace.shared_room_hint'));
            var exclusiveHint = @json(__('app.workspace.exclusive_room_hint'));
            select.addEventListener('change', function () {
                hint.textContent = select.value === 'shared' ? sharedHint : exclusiveHint;
            });
        })();
    </script>
@endsection
