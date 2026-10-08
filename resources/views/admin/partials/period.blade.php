{{--
    Period filter fields (inside a GET form). Preset select + custom range that
    only shows for "custom". Expects $preset, $from, $to.
--}}
@php
    $presets = ['today', 'last_7', 'last_30', 'last_90', 'this_month', 'previous_month', 'custom'];
@endphp
<div class="ls-field ls-filter-field">
    <label class="ls-label" for="f-preset">{{ __('app.admin_platform.period') }}</label>
    <select id="f-preset" name="preset" class="ls-select" onchange="this.form.querySelector('.ls-filter-range').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit();">
        @foreach ($presets as $p)
            <option value="{{ $p }}" @selected($preset === $p)>{{ __('app.admin_platform.presets.'.$p) }}</option>
        @endforeach
    </select>
</div>
<div class="ls-filter-range" @if ($preset !== 'custom') hidden @endif>
    <div class="ls-field ls-filter-field">
        <label class="ls-label" for="f-from">{{ __('app.admin_platform.from') }}</label>
        <input id="f-from" type="date" name="from" value="{{ $from }}" class="ls-input" dir="ltr">
    </div>
    <div class="ls-field ls-filter-field">
        <label class="ls-label" for="f-to">{{ __('app.admin_platform.to') }}</label>
        <input id="f-to" type="date" name="to" value="{{ $to }}" class="ls-input" dir="ltr">
    </div>
</div>
