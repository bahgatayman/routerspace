@extends('layouts.app')

@section('page-title', __('app.profile.my_profile'))

@section('content')
    <div class="max-w-3xl mx-auto">
        @if (session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">{{ session('success') }}</div>
        @endif

        @error('logo')
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">{{ $message }}</div>
        @enderror

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Owner Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Brand image -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('app.profile.brand_image') }}</h2>
                    <p class="text-sm text-gray-500 mb-5">{{ __('app.profile.brand_image_hint') }}</p>

                    <form method="POST" action="{{ route('profile.logo.update') }}" enctype="multipart/form-data"
                          class="flex flex-col sm:flex-row sm:items-center gap-5">
                        @csrf

                        <div class="shrink-0">
                            @if ($owner->logoUrl())
                                <img id="logo-preview" src="{{ $owner->logoUrl() }}" alt="{{ $owner->business_name }}"
                                     class="w-20 h-20 rounded-xl object-cover border border-gray-200 bg-white">
                            @else
                                <div class="w-20 h-20 rounded-xl bg-gradient-to-br from-blue-600 to-blue-400 text-white flex items-center justify-center text-2xl font-bold">
                                    {{ $owner->initials() }}
                                </div>
                                <img id="logo-preview" src="" alt=""
                                     class="hidden w-20 h-20 rounded-xl object-cover border border-gray-200 bg-white">
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            {{-- Picking a file uploads and saves it straight away (script below);
                                 the submit button is only the no-JavaScript fallback. --}}
                            <input type="file" name="logo" id="logo-input" accept="image/jpeg,image/png,image/webp" required class="ls-sr">
                            <label for="logo-input" id="logo-pick" class="ls-btn ls-btn--secondary" style="cursor: pointer" tabindex="0"
                                   onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); document.getElementById('logo-input').click(); }">
                                <x-ui.icon name="upload" />
                                <span>{{ $owner->logoUrl() ? __('app.profile.change_image') : __('app.profile.choose_image') }}</span>
                            </label>
                            <p class="text-xs text-gray-400 mt-2" id="logo-status" aria-live="polite">{{ __('app.profile.brand_image_rules') }}</p>
                            <p class="ls-error mt-2" id="logo-error" role="alert" hidden></p>

                            <noscript>
                                <button type="submit" class="ls-btn ls-btn--primary mt-3">{{ __('app.profile.upload_image') }}</button>
                            </noscript>
                        </div>
                    </form>

                    @if ($owner->logoUrl())
                        <form method="POST" action="{{ route('profile.logo.destroy') }}" class="mt-4 pt-4 border-t border-gray-100"
                              onsubmit="return confirm('{{ __('app.profile.remove_image_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">
                                {{ __('app.profile.remove_image') }}
                            </button>
                        </form>
                    @endif
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('app.profile.account_information') }}</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.common.name') }}</dt>
                            <dd class="text-gray-900 font-medium">{{ $owner->name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.common.email') }}</dt>
                            <dd class="text-gray-900">{{ $owner->email }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.label.business') }}</dt>
                            <dd class="text-gray-900 font-medium">{{ $owner->business_name }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- MikroTik Info -->
                @if ($owner->hasFeature('hotspot'))
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('app.profile.mikrotik_connection') }}</h2>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.label.mikrotik_host') }}</dt>
                            <dd class="text-gray-900">{{ $owner->mikrotik_host ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.label.mikrotik_port') }}</dt>
                            <dd class="text-gray-900">{{ $owner->mikrotik_port }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('app.label.mikrotik_username') }}</dt>
                            <dd class="text-gray-900">{{ $owner->mikrotik_username ?: '—' }}</dd>
                        </div>
                    </dl>
                </div>
                @endif
            </div>

            <!-- Plan & Subscription -->
            <div class="space-y-6">
                <!-- Current Plan -->
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl text-white p-6">
                    <p class="text-blue-100 text-sm">{{ __('app.profile.current_plan') }}</p>
                    <p class="text-3xl font-bold mt-1">{{ $owner->plan->name ?? __('app.profile.no_plan') }}</p>
                    <p class="text-blue-100 mt-1">{{ $owner->plan?->formattedPrice() }}</p>
                    <div class="mt-4 pt-4 border-t border-blue-400">
                        <div class="flex justify-between text-sm">
                            <span class="text-blue-100">{{ __('app.profile.members_used') }}</span>
                            <span class="font-semibold">{{ $usageCount }} / {{ $owner->plan?->max_members ?? 0 }}</span>
                        </div>
                        <div class="w-full bg-blue-400 rounded-full h-2 mt-2">
                            @php
                                $pct = $owner->plan
                                    ? ($usageCount / $owner->plan->max_members) * 100
                                    : 0;
                            @endphp
                            <div class="h-2 rounded-full bg-white" style="width: {{ min(100, $pct) }}%"></div>
                        </div>
                    </div>
                </div>

                <!-- Subscription Status -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-sm font-semibold text-gray-800 mb-3">{{ __('app.profile.subscription') }}</h2>
                    @php $status = $owner->subscriptionStatus(); @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ $status === 'active' ? 'bg-green-100 text-green-800' : '' }}
                        {{ $status === 'expiring_soon' ? 'bg-yellow-100 text-yellow-800' : '' }}
                        {{ $status === 'expired' ? 'bg-red-100 text-red-800' : '' }}
                        {{ $status === 'never' ? 'bg-gray-100 text-gray-800' : '' }}
                        {{ $status === 'disabled' ? 'bg-red-100 text-red-800' : '' }}">
                        {{ ucfirst(str_replace('_', ' ', $status)) }}
                    </span>
                    @if ($owner->subscription_expires_at)
                        <dl class="space-y-2 text-sm mt-3">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('app.profile.expires') }}</dt>
                                <dd class="text-gray-900 font-medium">{{ $owner->subscription_expires_at->format('Y-m-d') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('app.profile.days_remaining') }}</dt>
                                <dd class="text-gray-900">{{ $owner->daysUntilExpiry() }}</dd>
                            </div>
                        </dl>
                    @endif

                    <a href="{{ route('subscription.plans') }}"
                       class="mt-4 w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('app.subscription.renew_subscription') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Choose a file → check it → preview → upload and save at once.
        // The server re-validates (type + 2 MB) and replies with the usual message.
        (function () {
            const input = document.getElementById('logo-input');
            const pick = document.getElementById('logo-pick');
            const status = document.getElementById('logo-status');
            const error = document.getElementById('logo-error');
            @php $logoText = ['uploading' => __('app.profile.uploading'), 'tooBig' => __('app.profile.image_too_big'), 'wrongType' => __('app.profile.image_wrong_type')]; @endphp
            const T = @json($logoText);
            const types = ['image/jpeg', 'image/png', 'image/webp'];

            input.addEventListener('change', function () {
                const file = this.files[0];
                error.hidden = true;
                if (!file) return;

                if (!types.includes(file.type)) { error.textContent = T.wrongType; error.hidden = false; this.value = ''; return; }
                if (file.size > 2 * 1024 * 1024) { error.textContent = T.tooBig; error.hidden = false; this.value = ''; return; }

                const preview = document.getElementById('logo-preview');
                const placeholder = preview.previousElementSibling;
                preview.src = URL.createObjectURL(file);
                preview.classList.remove('hidden');
                if (placeholder) placeholder.classList.add('hidden');

                status.textContent = T.uploading;
                pick.classList.add('is-loading');
                pick.style.pointerEvents = 'none';
                pick.setAttribute('aria-busy', 'true');
                this.form.submit();
            });
        })();
    </script>
@endsection
