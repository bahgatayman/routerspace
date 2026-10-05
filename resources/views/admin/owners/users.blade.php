@extends('layouts.admin')

@section('page-title', $owner->business_name . ' - ' . __('app.user.hotspot_users'))

@section('content')
    @include('admin.business._header', ['active' => 'members', 'workspace' => null])

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.name') }}</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.phone') }}</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.download') }}</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.upload') }}</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.status') }}</th>
                        <th class="px-4 lg:px-6 py-3 font-medium">{{ __('app.table.th.created') }}</th>
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
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('app.status.active') }}</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ __('app.status.inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-4 lg:px-6 py-3 text-gray-500">{{ $user->created_at->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 lg:px-6 py-8 text-center text-gray-500">{{ __('app.empty.no_owner_users') }}</td>
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
