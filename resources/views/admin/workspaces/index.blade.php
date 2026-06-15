@extends('layouts.admin')

@section('page-title', 'Workspaces')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Workspaces</h1>
        <span class="text-sm text-gray-500">{{ $workspaces->total() }} total</span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($workspaces->isEmpty())
            <div class="text-center py-12">
                <p class="text-gray-500 text-sm">No workspaces found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Workspace</th>
                            <th class="px-4 py-3 font-medium">Owner</th>
                            <th class="px-4 py-3 font-medium">City</th>
                            <th class="px-4 py-3 font-medium">Rooms</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workspaces as $workspace)
                            <tr class="border-b border-gray-50 hover:bg-gray-50/50">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $workspace->name }}</td>
                                <td class="px-4 py-3 text-gray-600">
                                    <a href="/admin/owners/{{ $workspace->owner_id }}" class="text-blue-600 hover:underline">
                                        {{ $workspace->owner->business_name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $workspace->city ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $workspace->rooms_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        {{ $workspace->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                        {{ $workspace->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.workspaces.show', $workspace) }}"
                                        class="text-blue-600 hover:text-blue-800 text-xs font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100">
                {{ $workspaces->links() }}
            </div>
        @endif
    </div>
@endsection
