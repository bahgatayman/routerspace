@extends('layouts.app')

@section('page-title', 'Edit Room')

@section('content')
    <div class="max-w-lg mx-auto">
        <nav class="text-sm text-gray-500 mb-4">
            <a href="{{ route('workspaces.index') }}" class="text-blue-600 hover:text-blue-800">Workspaces</a>
            <span class="mx-2">/</span>
            <a href="{{ route('workspaces.show', $workspace) }}" class="text-blue-600 hover:text-blue-800">{{ $workspace->name }}</a>
            <span class="mx-2">/</span>
            <span class="text-gray-900">Edit Room</span>
        </nav>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-6">Edit Room</h1>

            <form method="POST" action="{{ route('rooms.update', [$workspace, $room]) }}">
                @csrf
                @method('PUT')

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $room->name) }}" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type <span class="text-red-500">*</span></label>
                        <select name="type" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            @foreach($roomTypes as $key => $label)
                                <option value="{{ $key }}" {{ old('type', $room->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity <span class="text-red-500">*</span></label>
                        <select name="capacity" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            @foreach($capacityOptions as $cap)
                                <option value="{{ $cap }}" {{ old('capacity', $room->capacity) == $cap ? 'selected' : '' }}>{{ $cap }} {{ Str::plural('person', $cap) }}</option>
                            @endforeach
                        </select>
                        @error('capacity') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Price per Hour <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-2 text-gray-500">$</span>
                            <input type="number" name="price_per_hour" value="{{ old('price_per_hour', $room->price_per_hour) }}" step="0.01" min="0" required
                                class="w-full border border-gray-300 rounded-lg pl-8 pr-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        @error('price_per_hour') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ old('description', $room->description) }}</textarea>
                        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        Update Room
                    </button>
                    <a href="{{ route('workspaces.show', $workspace) }}"
                        class="text-sm text-gray-600 hover:text-gray-800">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
