@extends('layouts.admin')

@section('page-title', $owner->business_name . ' - Users')

@section('content')
    <div class="mb-6">
        <a href="/admin/owners/{{ $owner->id }}" class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-1 mb-4">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to {{ $owner->business_name }}
        </a>
        <h1 class="text-2xl font-bold text-gray-900">{{ $owner->business_name }} — Hotspot Users</h1>
        <p class="text-sm text-gray-500 mt-1">Read-only view of all hotspot users for this owner.</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                        <th class="px-4 lg:px-6 py-3 font-medium">Name</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">Phone</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">Speed ↓</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">Speed ↑</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">Status</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                            <td class="px-4 lg:px-6 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                            <td class="px-4 lg:px-6 py-3 text-gray-700">{{ $user->phone }}</td>
                            <td class="px-4 lg:px-6 py-3 text-gray-700">{{ $user->speed_download }}</td>
                            <td class="px-4 lg:px-6 py-3 text-gray-700">{{ $user->speed_upload }}</td>
                            <td class="px-4 lg:px-6 py-3">
                                @if ($user->status === 'active')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 lg:px-6 py-3 text-gray-500">{{ $user->created_at->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 lg:px-6 py-8 text-center text-gray-500">No users found for this owner.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="px-4 lg:px-6 py-3 border-t border-gray-100">
                {{ $users->links() }}
            </div>
        @endif
    </div>
@endsection
