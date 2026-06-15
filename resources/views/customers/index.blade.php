@extends('layouts.app')

@section('page-title', 'Customers')

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
        <a href="/customers/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
            + Add Customer
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b border-gray-100">
            <form method="GET" action="/customers" class="flex gap-3">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or phone..."
                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">Search</button>
                @if ($search)
                    <a href="/customers" class="text-sm text-gray-500 hover:text-gray-700 py-2">Clear</a>
                @endif
            </form>
        </div>

        @if ($customers->isEmpty())
            <div class="p-12 text-center">
                <p class="text-gray-400 text-sm">No customers found.</p>
                <a href="/customers/create" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">Add your first customer</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Phone</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Bookings</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $customer->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $customer->phone ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $customer->email ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $customer->bookings_count }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        <a href="/customers/{{ $customer->id }}" class="text-blue-600 hover:underline text-xs font-medium">View</a>
                                        <a href="/customers/{{ $customer->id }}/edit" class="text-gray-600 hover:underline text-xs font-medium">Edit</a>
                                        <form method="POST" action="/customers/{{ $customer->id }}" onsubmit="return confirm('Delete this customer?')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline text-xs font-medium">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $customers->appends(request()->query())->links() }}
            </div>
        @endif
    </div>
@endsection
