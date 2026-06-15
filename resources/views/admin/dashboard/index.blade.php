@extends('layouts.admin')

@section('page-title', 'Dashboard')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Admin Dashboard</h1>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Total Owners</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalOwners }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-gray-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Active Subscriptions</p>
                    <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1">{{ $activeOwners }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Expiring Soon</p>
                    <p class="text-2xl lg:text-3xl font-bold text-yellow-600 mt-1">{{ $expiringSoon }}</p>
                    <p class="text-xs text-gray-400 mt-1">Within 7 days</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-yellow-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Expired</p>
                    <p class="text-2xl lg:text-3xl font-bold text-red-600 mt-1">{{ $expiredOwners }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-red-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 lg:gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Total Workspaces</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalWorkspaces }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-indigo-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Total Rooms</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalRooms }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-purple-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Total Hotspot Users</h2>
                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-gray-900">{{ $totalUsers }}</p>
            <p class="text-sm text-gray-500 mt-1">Across all owners</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Renewals</h2>
            </div>
            @if ($recentRenewals->isEmpty())
                <p class="text-sm text-gray-500">No renewals yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-gray-100">
                                <th class="pb-2 font-medium">Owner</th>
                                <th class="pb-2 font-medium">Months</th>
                                <th class="pb-2 font-medium">Expires</th>
                                <th class="pb-2 font-medium">By</th>
                                <th class="pb-2 font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentRenewals as $renewal)
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 text-gray-800">{{ $renewal->owner->business_name }}</td>
                                    <td class="py-2">{{ $renewal->months }}</td>
                                    <td class="py-2">{{ $renewal->expires_at->format('Y-m-d') }}</td>
                                    <td class="py-2">{{ $renewal->admin->name }}</td>
                                    <td class="py-2">{{ $renewal->created_at->format('Y-m-d') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Total Bookings</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalBookings }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Today's Bookings</p>
                    <p class="text-2xl lg:text-3xl font-bold text-blue-600 mt-1">{{ $todayBookings }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Monthly Revenue</p>
                    <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1">{{ number_format($monthRevenue, 2) }} EGP</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>
@endsection
