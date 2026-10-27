@extends('layouts.app')

{{--
    Add Room — also "Add another use" of an existing room ($physicalRoom set):
    the same form, pricing and validation, plus one line saying the new use
    shares that room's availability.
--}}
@php
    $physicalRoom = $physicalRoom ?? null;
    $formTitle = $physicalRoom ? __('app.workspace.uses.add_title', ['room' => $physicalRoom->name]) : __('app.workspace.add_room');
@endphp

@section('page-title', $physicalRoom ? __('app.workspace.uses.add') : __('app.workspace.add_room'))

@section('content')
    <x-ui.flash />
    <div class="max-w-2xl mx-auto">
        <nav class="text-sm text-gray-500 mb-4">
            <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}" class="text-blue-600 hover:text-blue-800">{{ $workspace->name }}</a>
            <span class="mx-2">/</span>
            @if ($physicalRoom)
                <a href="{{ route('rooms.edit', [$workspace, $physicalRoom]) }}" class="text-blue-600 hover:text-blue-800">{{ $physicalRoom->name }}</a>
                <span class="mx-2">/</span>
            @endif
            <span class="text-gray-900">{{ $physicalRoom ? __('app.workspace.uses.add') : __('app.workspace.add_room') }}</span>
        </nav>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-xl font-bold text-gray-900 {{ $physicalRoom ? 'mb-2' : 'mb-6' }}">{{ $formTitle }}</h1>
            @if ($physicalRoom)
                <p class="text-sm text-gray-500 mb-4">{{ __('app.workspace.uses.add_intro') }}</p>
                <div class="mb-6">
                    <x-ui.banner tone="info">{{ __('app.workspace.uses.add_shares_hint', ['room' => $physicalRoom->name]) }}</x-ui.banner>
                </div>
            @endif

            <form method="POST" action="{{ $physicalRoom ? route('rooms.uses.store', [$workspace, $physicalRoom]) : route('rooms.store', $workspace) }}">
                @csrf

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $physicalRoom ? __('app.workspace.uses.name') : __('app.workspace.room_name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" required
                            @if ($physicalRoom) placeholder="{{ __('app.workspace.uses.name_placeholder') }}" @endif
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @if ($physicalRoom) <p class="text-xs text-gray-500 mt-1">{{ __('app.workspace.uses.name_hint') }}</p> @endif
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.type') }} <span class="text-red-500">*</span></label>
                        <select name="type" id="room_type" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">{{ __('app.placeholder.select_type') }}</option>
                            @foreach($roomTypes as $key => $label)
                                <option value="{{ $key }}" {{ old('type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-500 mt-1" id="room_type_hint">
                            {{ old('type') === 'shared' ? __('app.workspace.shared_room_hint') : __('app.workspace.exclusive_room_hint') }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.capacity') }} <span class="text-red-500">*</span></label>
                        <input type="number" name="capacity" min="1" max="999"
                               value="{{ old('capacity', $physicalRoom?->capacity ?? 1) }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('capacity') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    @include('workspaces.rooms._pricing', ['room' => null])

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.description') }}</label>
                        <textarea name="description" rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ old('description') }}</textarea>
                        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        {{ $physicalRoom ? __('app.workspace.uses.add') : __('app.btn.add_room') }}
                    </button>
                    <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}"
                        class="text-sm text-gray-600 hover:text-gray-800">{{ __('app.common.cancel') }}</a>
                </div>
            </form>
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
