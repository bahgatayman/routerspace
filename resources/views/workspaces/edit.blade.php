@extends('layouts.app')

@section('page-title', __('app.workspace.edit_workspace'))

@section('content')
    <div class="max-w-2xl mx-auto">
        <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}" class="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block">&larr; {{ __('app.btn.back_to_workspaces') }}</a>

        {{-- Photo upload results (success toast / limit and failure banners). --}}
        <x-ui.flash />

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" id="workspace-details" style="scroll-margin-top: var(--space-6)">
            <h1 class="text-xl font-bold text-gray-900 mb-6">{{ __('app.workspace.edit_workspace') }}</h1>

            <form method="POST" action="{{ route('workspaces.update', $workspace) }}">
                @csrf
                @method('PUT')

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.workspace_name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $workspace->name) }}" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.description') }}</label>
                        <textarea name="description" rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ old('description', $workspace->description) }}</textarea>
                        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.address') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="address" value="{{ old('address', $workspace->address) }}" required maxlength="500" autocomplete="street-address"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('address') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.city') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="city" value="{{ old('city', $workspace->city) }}" required maxlength="100"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('city') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('app.workspace.form.mobile') }} <span class="text-red-500">*</span></label>
                        <input type="tel" name="phone" value="{{ old('phone', $workspace->phone) }}" required maxlength="20" inputmode="tel" dir="ltr" placeholder="{{ __('app.workspace.form.phone_ph') }}"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        @error('phone') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        {{ __('app.btn.update_workspace') }}
                    </button>
                    <a href="{{ route('workspaces.index', ['workspace' => $workspace->id]) }}"
                        class="text-sm text-gray-600 hover:text-gray-800">{{ __('app.common.cancel') }}</a>
                </div>
            </form>
        </div>

        @include('workspaces._publication')

        {{-- Workspace photos: own gallery (separate from room photos), same manager UI as rooms. --}}
        @include('workspaces._photo-manager', [
            'anchor' => 'workspace-photos',
            'title' => __('app.workspace.workspace_photos'),
            'hint' => __('app.workspace.workspace_photos_hint'),
            'emptyText' => __('app.workspace.workspace_photos_empty'),
            'coverLabel' => __('app.workspace.primary_photo'),
            'setCoverLabel' => __('app.workspace.set_as_primary'),
            'limitsHint' => __('app.workspace.photos_limits', ['max' => \App\Http\Controllers\WorkspaceImageController::MAX_IMAGES_PER_WORKSPACE]),
            'images' => $workspace->images->map(fn ($image) => [
                'id' => $image->id,
                'thumb' => $image->thumbnailUrl(),
                'cover' => $image->is_primary,
                'coverUrl' => route('workspaces.images.primary', [$workspace, $image]),
                'deleteUrl' => route('workspaces.images.destroy', [$workspace, $image]),
            ])->all(),
            'reorderUrl' => route('workspaces.images.reorder', $workspace),
            'storeUrl' => route('workspaces.images.store', $workspace),
        ])
    </div>
@endsection
