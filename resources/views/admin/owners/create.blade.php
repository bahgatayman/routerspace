@extends('layouts.admin')

@section('page-title', 'Add Owner')

@section('content')
    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Add New Owner</h1>

        <form method="POST" action="/admin/owners" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:p-8 space-y-6">
            @csrf

            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">Owner Details</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                        <input type="text" name="business_name" value="{{ old('business_name') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('business_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">MikroTik Connection</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Host</label>
                        <input type="text" name="mikrotik_host" value="{{ old('mikrotik_host') }}" required placeholder="e.g. 192.168.88.1"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('mikrotik_host') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                        <input type="number" name="mikrotik_port" value="{{ old('mikrotik_port', 8728) }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('mikrotik_port') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" name="mikrotik_username" value="{{ old('mikrotik_username') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('mikrotik_username') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="mikrotik_password" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        @error('mikrotik_password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">Initial Subscription</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Months</label>
                    <input type="number" name="months" value="{{ old('months', 1) }}" min="1" max="24" required
                           class="w-full sm:w-48 border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    <p class="text-xs text-gray-500 mt-1">Owner will have access for this many months starting today.</p>
                    @error('months') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3 pt-4">
                <button type="submit" class="bg-red-600 text-white px-6 py-2.5 rounded-lg hover:bg-red-700 transition font-medium shadow-sm">
                    Create Owner
                </button>
                <a href="/admin/owners" class="text-gray-600 hover:text-gray-800 text-sm font-medium">Cancel</a>
            </div>
        </form>
    </div>
@endsection
