{{--
    Photo gallery manager shared by Room photos and Workspace photos. Driven by
    panel.js initRoomPhotoManager through data attributes only (dropzone,
    drag-to-reorder → reorderUrl, set-cover/primary, remove) — no page JS.

    Expects:
      $anchor      id of the card (deep-link target, e.g. "room-photos")
      $title, $hint, $emptyText, $coverLabel, $setCoverLabel
      $limitsHint  optional line under the dropzone (formats / size / count)
      $images      list of ['id', 'thumb', 'cover' => bool, 'coverUrl', 'deleteUrl']
      $reorderUrl, $storeUrl
--}}
<div class="ls-card mt-6" id="{{ $anchor }}" data-ls-photo-manager style="scroll-margin-top: var(--space-6)">
    <div class="ls-card-head"><h2 class="ls-card-title">{{ $title }}</h2></div>
    <div class="ls-card-body">
        <p class="ls-hint" style="margin-bottom: var(--space-4)">{{ $hint }}</p>

        @if (count($images) > 0)
            <div class="ls-room-gallery-manager" data-ls-room-images
                 data-reorder-url="{{ $reorderUrl }}">
                @foreach ($images as $image)
                    <div class="ls-room-image" draggable="true" data-image-id="{{ $image['id'] }}">
                        <img src="{{ $image['thumb'] }}" alt="" loading="lazy">
                        @if ($image['cover'])
                            <span class="ls-room-image-cover-tag">{{ $coverLabel }}</span>
                        @endif
                        <div class="ls-room-image-actions">
                            @unless ($image['cover'])
                                <button type="button" data-ls-set-cover="{{ $image['coverUrl'] }}">{{ $setCoverLabel }}</button>
                            @endunless
                            <button type="button" class="is-danger" data-ls-remove-image="{{ $image['deleteUrl'] }}"
                                    data-confirm="{{ __('app.workspace.remove_photo') }}?">{{ __('app.common.delete') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="ls-hint" style="margin-top: var(--space-2)">{{ __('app.workspace.reorder_photos_hint') }}</p>
        @else
            <p class="ls-faint">{{ $emptyText }}</p>
        @endif

        <form method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" style="margin-top: var(--space-5)">
            @csrf
            <div class="ls-room-dropzone" data-ls-dropzone>
                <x-ui.icon name="upload" />
                <span>{{ __('app.workspace.drop_photos_hint') }}</span>
                <input type="file" name="images[]" multiple accept="image/jpeg,image/jpg,image/png,image/webp">
            </div>
            @if (! empty($limitsHint))
                <p class="ls-hint" style="margin-top: var(--space-2)">{{ $limitsHint }}</p>
            @endif
            <div class="ls-room-pending" data-ls-pending></div>
            @error('images') <p class="ls-error" style="margin-top: var(--space-2)">{{ $message }}</p> @enderror
            @error('images.*') <p class="ls-error" style="margin-top: var(--space-2)">{{ $message }}</p> @enderror
            <div style="margin-top: var(--space-4)">
                <x-ui.button type="submit" variant="primary" icon="plus" data-ls-upload-submit disabled>
                    {{ __('app.workspace.add_photos') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
