@extends('layouts.admin')

@section('page-title', $workspace->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-blue-600 hover:text-blue-800 mb-2 inline-block">&larr; Back to Workspaces</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ $workspace->name }}</h1>
    </div>

    <!-- Workspace Info Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <span class="text-xs text-gray-500 uppercase tracking-wider">Owner</span>
                <p class="mt-1">
                    <a href="/admin/owners/{{ $workspace->owner_id }}" class="text-blue-600 hover:underline text-sm">
                        {{ $workspace->owner->business_name }}
                    </a>
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-500 uppercase tracking-wider">Status</span>
                <p class="mt-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ $workspace->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $workspace->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </p>
            </div>
            @if($workspace->city)
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wider">City</span>
                    <p class="mt-1 text-sm text-gray-900">{{ $workspace->city }}</p>
                </div>
            @endif
            @if($workspace->address)
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wider">Address</span>
                    <p class="mt-1 text-sm text-gray-900">{{ $workspace->address }}</p>
                </div>
            @endif
            @if($workspace->phone)
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wider">Phone</span>
                    <p class="mt-1 text-sm text-gray-900">{{ $workspace->phone }}</p>
                </div>
            @endif
            @if($workspace->description)
                <div class="md:col-span-2">
                    <span class="text-xs text-gray-500 uppercase tracking-wider">Description</span>
                    <p class="mt-1 text-sm text-gray-900">{{ $workspace->description }}</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Rooms Section -->
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Rooms ({{ $workspace->rooms->count() }})</h2>

    @if($roomsByType->isEmpty())
        <div class="text-center py-12 bg-white rounded-xl border border-gray-100">
            <p class="text-gray-500 text-sm">No rooms in this workspace.</p>
        </div>
    @else
        @foreach($roomsByType as $type => $rooms)
            <div class="mb-8">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                    {{ $rooms->first()->typeLabel() }} ({{ $rooms->count() }})
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($rooms as $room)
                        <div class="bg-white rounded-lg border border-gray-200 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-900">{{ $room->name }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $room->is_available ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }}">
                                    {{ $room->is_available ? 'Available' : 'Unavailable' }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-500 space-y-1">
                                <p>Capacity: {{ $room->capacity }}</p>
                                <p>${{ number_format($room->price_per_hour, 2) }} / hour</p>
                                @if($room->description)
                                    <p class="text-gray-400 mt-2">{{ Str::limit($room->description, 60) }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
@endsection
