@extends('layouts.app')

@section('page-title', __('app.staff.edit_staff'))

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.staff.edit_staff') }}</h1>
        <a href="/staff" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('app.common.back') }}</a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col lg:flex-row gap-6 max-w-5xl">
        <form method="POST" action="/staff/{{ $staff->id }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex-1 space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.staff.name') }}</label>
                    <input type="text" name="name" value="{{ old('name', $staff->name) }}" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.staff.email') }}</label>
                    <input type="email" name="email" value="{{ old('email', $staff->email) }}" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label for="staff-password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.staff.password') }}</label>
                    <x-ui.password name="password" id="staff-password" minlength="8" aria-describedby="staff-password-hint"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <p class="text-xs text-gray-500 mt-1" id="staff-password-hint">{{ __('app.staff.password_hint') }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.staff.role') }}</label>
                    <select name="role_id" id="role-select"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">{{ __('app.staff.no_role') }}</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" {{ (old('role_id', $staff->role_id) == $role->id) ? 'selected' : '' }}
                                    data-permission-keys="{{ $role->permissions->pluck('key')->implode(',') }}">
                                {{ __('app.role.'.$role->key) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">{{ __('app.staff.role_hint') }}</p>
                </div>
            </div>

            <div id="permissions" class="ls-scroll-anchor">
                <div class="flex items-center justify-between mb-2">
                    <p class="block text-sm font-medium text-gray-700">{{ __('app.staff.permissions') }}</p>
                    <button type="submit" form="reset-permissions-form" class="text-xs text-blue-600 hover:underline">
                        {{ __('app.staff.reset_to_role_defaults') }}
                    </button>
                </div>
                @include('staff._permission-grid', ['permissions' => $permissions, 'granted' => $grantedPermissionIds])
            </div>

            <div class="flex gap-3">
                <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                    {{ __('app.staff.save') }}
                </button>
                <a href="/staff" class="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">
                    {{ __('app.common.cancel') }}
                </a>
            </div>
        </form>

        <div class="w-full lg:w-64 shrink-0 space-y-3">
            <a href="/staff/{{ $staff->id }}/activity" class="block bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-blue-600 hover:bg-blue-50 transition text-center">
                {{ __('app.staff.view_activity') }}
            </a>

            <form method="POST" action="/staff/{{ $staff->id }}/toggle-status" onsubmit="return confirm('{{ $staff->is_active ? __('app.staff.confirm_disable') : '' }}')">
                @csrf
                <button type="submit" class="w-full bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium {{ $staff->is_active ? 'text-amber-600 hover:bg-amber-50' : 'text-green-600 hover:bg-green-50' }} transition">
                    {{ $staff->is_active ? __('app.staff.disable') : __('app.staff.enable') }}
                </button>
            </form>

            <form method="POST" action="/staff/{{ $staff->id }}" onsubmit="return confirm('{{ __('app.staff.confirm_delete') }}')">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-sm font-medium text-red-600 hover:bg-red-50 transition">
                    {{ __('app.common.delete') }}
                </button>
            </form>
        </div>
    </div>

    <form id="reset-permissions-form" method="POST" action="/staff/{{ $staff->id }}/reset-permissions" class="hidden"></form>

    <script>
        (function () {
            const grid = document.getElementById('permission-grid');

            function refreshGroup(group) {
                const items = [...group.querySelectorAll('[data-permission-item]')];
                const checked = items.filter(box => box.checked).length;
                const toggle = group.querySelector('[data-group-toggle]');
                toggle.checked = checked > 0 && checked === items.length;
                toggle.indeterminate = checked > 0 && checked < items.length;
                group.querySelector('[data-group-count]').textContent = `${checked}/${items.length}`;
            }

            function refreshAllGroups() {
                grid.querySelectorAll('[data-permission-group]').forEach(refreshGroup);
            }

            grid.addEventListener('change', function (e) {
                if (e.target.matches('[data-group-toggle]')) {
                    const ids = (e.target.dataset.groupIds || '').split(',').filter(Boolean);
                    ids.forEach(id => {
                        const box = grid.querySelector(`[data-permission-item][value="${id}"]`);
                        if (box) box.checked = e.target.checked;
                    });
                    refreshAllGroups();
                    return;
                }
                if (e.target.matches('[data-permission-item]')) {
                    refreshGroup(e.target.closest('[data-permission-group]'));
                }
            });

            document.getElementById('role-select').addEventListener('change', function () {
                const selected = this.options[this.selectedIndex];
                const keys = (selected.dataset.permissionKeys || '').split(',').filter(Boolean);
                grid.querySelectorAll('[data-permission-item]').forEach(function (box) {
                    box.checked = keys.includes(box.dataset.permissionKey);
                });
                refreshAllGroups();
            });

            refreshAllGroups();
        })();
    </script>
@endsection
