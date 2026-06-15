@extends('layouts.app')

@section('page-title', 'Dashboard')

@section('content')
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Welcome, {{ $owner->business_name }}</h1>

    @if ($mikrotikError)
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6">
            MikroTik unreachable — live stats unavailable
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Total Users</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalUsers }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Active Users</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $activeUsers }}</p>
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
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Online Now</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $activeSessions }}</p>
                    <p class="text-xs text-gray-400 mt-1">Live from MikroTik</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-purple-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">Speed Profiles</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalProfiles }}</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-gray-700 mb-4">Quick Links</h2>
    <div class="flex flex-col sm:flex-row flex-wrap gap-3 lg:gap-4">
        <a href="/users/create" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm text-center">
            Add New User
        </a>
        <a href="/sessions" class="bg-purple-600 text-white px-5 py-2.5 rounded-lg hover:bg-purple-700 transition text-sm font-medium shadow-sm text-center">
            View Active Sessions
        </a>
        <a href="/speed-profiles" class="bg-orange-600 text-white px-5 py-2.5 rounded-lg hover:bg-orange-700 transition text-sm font-medium shadow-sm text-center">
            Manage Speed Profiles
        </a>
    </div>

    @if(auth('owner')->user()->hasFeature('booking'))
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Booking Overview</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">Today's Bookings</p>
                        <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $todayBookings }}</p>
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
                        <p class="text-xs lg:text-sm font-medium text-gray-500">Pending Confirmations</p>
                        <p class="text-2xl lg:text-3xl font-bold {{ $pendingBookings > 0 ? 'text-yellow-600' : 'text-gray-900' }} mt-1">{{ $pendingBookings }}</p>
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
                        <p class="text-xs lg:text-sm font-medium text-gray-500">This Month Revenue</p>
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
    @endif

    @if(auth('owner')->user()->hasFeature('workspace'))
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Workspace Overview</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">Total Workspaces</p>
                        <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalWorkspaces ?? 0 }}</p>
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
                        <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalRooms ?? 0 }}</p>
                    </div>
                    <div class="w-10 h-10 lg:w-12 lg:h-12 bg-purple-50 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">Available Rooms</p>
                        <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1">{{ $availableRooms ?? 0 }}</p>
                    </div>
                    <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Your Features</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @php $ownerFeatures = auth('owner')->user()->features()->where('is_active', true)->get(); @endphp
            @foreach ($ownerFeatures as $feature)
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                    @include('admin.features._icon', ['icon' => $feature->icon])
                    <div>
                        <p class="font-medium text-gray-900 text-sm">{{ $feature->name }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $feature->description }}</p>
                    </div>
                </div>
            @endforeach
            @if ($ownerFeatures->isEmpty())
                <div class="col-span-3 text-center py-8 text-gray-400 text-sm">
                    No features enabled yet. Contact your administrator.
                </div>
            @endif
        </div>
    </div>
@endsection
