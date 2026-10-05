@extends('layouts.admin')

@section('page-title', __('app.admin_notif.title'))

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.admin_notif.title') }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ __('app.admin_notif.subtitle') }}</p>
    </div>

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
            @foreach ($errors->all() as $error)<p class="text-sm">{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="grid lg:grid-cols-5 gap-6">
        <!-- Compose -->
        <div class="lg:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form method="POST" action="{{ route('admin.notifications.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.admin_notif.message_title') }}</label>
                    <input type="text" name="title" id="title" value="{{ old('title') }}" required maxlength="120"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>

                <div>
                    <label for="body" class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.admin_notif.message_body') }}</label>
                    <textarea name="body" id="body" rows="4" maxlength="1000"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">{{ old('body') }}</textarea>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="level" class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.admin_notif.level') }}</label>
                        <select name="level" id="level" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500">
                            <option value="info" @selected(old('level')==='info')>{{ __('app.admin_notif.level_info') }}</option>
                            <option value="success" @selected(old('level')==='success')>{{ __('app.admin_notif.level_success') }}</option>
                            <option value="warning" @selected(old('level')==='warning')>{{ __('app.admin_notif.level_warning') }}</option>
                            <option value="danger" @selected(old('level')==='danger')>{{ __('app.admin_notif.level_danger') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="action_url" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('app.admin_notif.action_url') }} <span class="text-gray-400 font-normal">({{ __('app.admin_notif.optional') }})</span>
                        </label>
                        <input type="text" name="action_url" id="action_url" value="{{ old('action_url') }}" placeholder="/dashboard"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                </div>
                <p class="-mt-3 text-xs text-gray-400">{{ __('app.admin_notif.action_url_hint') }}</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('app.admin_notif.target') }}</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="target" value="all" class="text-brand-600 focus:ring-brand-500" {{ old('target', 'active') === 'all' ? 'checked' : '' }} onclick="toggleOwnerPicker()">
                            {{ __('app.admin_notif.target_all') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="target" value="active" class="text-brand-600 focus:ring-brand-500" {{ old('target', 'active') === 'active' ? 'checked' : '' }} onclick="toggleOwnerPicker()">
                            {{ __('app.admin_notif.target_active') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="target" value="selected" class="text-brand-600 focus:ring-brand-500" {{ old('target') === 'selected' ? 'checked' : '' }} onclick="toggleOwnerPicker()">
                            {{ __('app.admin_notif.target_selected') }}
                        </label>
                    </div>

                    <div id="owner-picker" class="mt-3 {{ old('target') === 'selected' ? '' : 'hidden' }}">
                        <div class="border border-gray-200 rounded-lg max-h-56 overflow-y-auto divide-y divide-gray-50">
                            @forelse($owners as $owner)
                                <label class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">
                                    <input type="checkbox" name="owner_ids[]" value="{{ $owner->id }}" class="text-brand-600 focus:ring-brand-500"
                                           {{ collect(old('owner_ids'))->contains($owner->id) ? 'checked' : '' }}>
                                    <span class="font-medium text-gray-800">{{ $owner->business_name ?: $owner->name }}</span>
                                    @unless($owner->is_active)<span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{{ __('app.status.inactive') }}</span>@endunless
                                </label>
                            @empty
                                <p class="px-3 py-4 text-sm text-gray-400">{{ __('app.empty.no_owners') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-brand-600 text-white py-2.5 rounded-lg hover:bg-brand-700 transition font-medium shadow-sm">
                    {{ __('app.admin_notif.send') }}
                </button>
            </form>
        </div>

        <!-- Recent broadcasts -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">{{ __('app.admin_notif.recent_sends') }}</h2>
            @if($recent->isEmpty())
                <p class="text-sm text-gray-400">{{ __('app.admin_notif.no_sends') }}</p>
            @else
                <ul class="space-y-3">
                    @foreach($recent as $r)
                        @php $c = ['success' => 'green', 'warning' => 'yellow', 'danger' => 'red'][$r->level] ?? 'blue'; @endphp
                        <li class="border border-gray-100 rounded-lg p-3">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-800">{{ $r->title }}</p>
                                <span class="shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full bg-{{ $c }}-100 text-{{ $c }}-700">{{ __('app.admin_notif.level_' . $r->level) }}</span>
                            </div>
                            @if($r->body)<p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ $r->body }}</p>@endif
                            <div class="flex items-center justify-between mt-2 text-[11px] text-gray-400">
                                <span>{{ $r->sent_at->format('M j, H:i') }}</span>
                                <span>{{ __('app.admin_notif.read_of', ['read' => $r->read_count, 'total' => $r->recipients]) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

<script>
function toggleOwnerPicker() {
    var checked = document.querySelector('input[name="target"]:checked');
    var picker = document.getElementById('owner-picker');
    picker.classList.toggle('hidden', !(checked && checked.value === 'selected'));
}
</script>
@endsection
