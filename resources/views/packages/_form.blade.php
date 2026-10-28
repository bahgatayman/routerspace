{{--
    Hour package template form (create + edit). Hours accept decimals and are
    stored as minutes server-side; rooms are "all" or a specific subset.
--}}
@php
    $currency = app()->getLocale() === 'ar' ? 'ج.م' : 'EGP';
    $selectedRooms = array_map('intval', old('room_ids', $template->room_ids ?? []));
    // New templates start with neither option picked — the owner must choose
    // explicitly. Editing an existing template still reflects its saved scope.
    $roomScope = old('room_scope', $template->exists ? (empty($template->room_ids) ? 'all' : 'specific') : null);
    $hoursValue = old('hours', $template->total_minutes ? rtrim(rtrim(number_format($template->total_minutes / 60, 2, '.', ''), '0'), '.') : '');
@endphp
<div class="ls-pkg-form">
    <x-ui.input name="name" :label="__('app.packages.name')" :value="old('name', $template->name)" :placeholder="__('app.packages.name_ph')" maxlength="80" required />

    <div class="ls-pkg-form-row">
        <x-ui.input name="hours" type="number" :label="__('app.packages.hours')" :value="$hoursValue" step="0.25" min="0.25" inputmode="decimal" :hint="__('app.packages.hours_hint')" required />
        <div class="ls-field">
            <label class="ls-label" for="f-price">{{ __('app.packages.price') }} <span class="ls-req" aria-hidden="true">*</span></label>
            <div class="ls-input-affix"><span aria-hidden="true">{{ $currency }}</span>
                <input type="number" step="0.01" min="0" id="f-price" name="price" class="ls-input {{ $errors->has('price') ? 'is-invalid' : '' }}" inputmode="decimal" required
                       value="{{ old('price', $template->price) }}">
            </div>
            @error('price') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
        </div>
        <x-ui.input name="validity_days" type="number" :label="__('app.packages.validity_days')" :value="old('validity_days', $template->validity_days)" min="1" max="3650" required />
    </div>

    <fieldset class="ls-inv-group">
        <legend class="ls-inv-legend">{{ __('app.packages.rooms') }} <span class="ls-req" aria-hidden="true">*</span></legend>
        <div class="ls-inv-seg" role="radiogroup">
            <label><input type="radio" name="room_scope" value="all" data-pkg-scope required @checked($roomScope === 'all')><span>{{ __('app.packages.all_rooms') }}</span></label>
            <label><input type="radio" name="room_scope" value="specific" data-pkg-scope required @checked($roomScope === 'specific')><span>{{ __('app.packages.specific_rooms') }}</span></label>
        </div>
        @error('room_scope') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
        <div class="ls-pkg-rooms" data-pkg-rooms @if ($roomScope !== 'specific') hidden @endif>
            @foreach ($roomGroups as $group => $rooms)
                <div class="ls-pkg-room-group">
                    <div class="ls-pkg-room-head">{{ $group }}</div>
                    @foreach ($rooms as $room)
                        <label class="ls-pkg-room">
                            <input type="checkbox" name="room_ids[]" value="{{ $room->id }}" @checked(in_array($room->id, $selectedRooms, true))>
                            <span>{{ $room->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endforeach
        </div>
        @error('room_ids') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
    </fieldset>

    <label class="ls-inv-switch">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active ?? true))>
        <span><b>{{ __('app.packages.active') }}</b><small>{{ __('app.packages.active_hint') }}</small></span>
    </label>
</div>

<script>
    (function () {
        const rooms = document.querySelector('[data-pkg-rooms]');
        document.querySelectorAll('[data-pkg-scope]').forEach((r) => r.addEventListener('change', () => {
            rooms.hidden = document.querySelector('[data-pkg-scope]:checked').value !== 'specific';
        }));
    })();
</script>
