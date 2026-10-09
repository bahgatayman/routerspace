@extends('layouts.app')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ __('app.mikrotik.tasks_title') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('app.mikrotik.tasks_subtitle') }}</p>
        </div>
        @if ($tasks->isNotEmpty())
            <form method="POST" action="/router-sync-tasks/retry-all">
                @csrf
                <button type="submit" class="bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition text-sm font-medium">
                    {{ __('app.mikrotik.retry_all') }}
                </button>
            </form>
        @endif
    </div>

    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded mb-4">{{ session('warning') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        @if ($tasks->isEmpty())
            <p class="text-sm text-gray-400 text-center py-10">{{ __('app.mikrotik.no_tasks') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b bg-gray-50">
                            <th class="px-4 py-3">{{ __('app.table.th.name') }}</th>
                            <th class="px-4 py-3">{{ __('app.mikrotik.col_type') }}</th>
                            <th class="px-4 py-3">{{ __('app.common.status') }}</th>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">{{ __('app.mikrotik.col_details') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr class="border-b last:border-0">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $task->hotspotUser?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ __('app.mikrotik.type_'.$task->type) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $task->status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700' }}">
                                        {{ __('app.mikrotik.sync_status_'.$task->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ __('app.mikrotik.attempts', ['count' => $task->attempts]) }}</td>
                                <td class="px-4 py-3 text-gray-400 max-w-xs truncate" title="{{ $task->last_error }}">{{ $task->last_error }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="/router-sync-tasks/{{ $task->id }}/retry">
                                        @csrf
                                        <button type="submit" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ __('app.mikrotik.retry') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
