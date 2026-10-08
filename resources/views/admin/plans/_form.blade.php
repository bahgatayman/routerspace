{{-- Plan create/edit form. Expects $features, optional $plan. --}}
@php
    $plan = $plan ?? null;
    $selected = collect(old('features', $plan?->features ?? []));
    $field = fn (string $name, string $label, string $type = 'number', array $attrs = [], ?string $hint = null) => compact('name', 'label', 'type', 'attrs', 'hint');
    $fields = [
        $field('name', __('app.plan.name'), 'text', ['required' => true, 'maxlength' => 100]),
        $field('slug', __('app.plan.slug'), 'text', ['required' => true, 'dir' => 'ltr']),
        $field('price_per_month', __('app.plan.price_per_month'), 'number', ['required' => true, 'min' => 0, 'step' => '0.01']),
        $field('max_members', __('app.plan.max_members'), 'number', ['required' => true, 'min' => 1]),
        $field('max_workspaces', __('app.plan.max_workspaces'), 'number', ['required' => true, 'min' => 0], __('app.plan.unlimited_hint')),
        $field('max_rooms', __('app.plan.max_rooms'), 'number', ['required' => true, 'min' => 0], __('app.plan.unlimited_hint')),
        $field('max_products', __('app.plan.max_products'), 'number', ['required' => true, 'min' => 0], __('app.plan.unlimited_hint')),
        $field('sort_order', __('app.plan.sort_order'), 'number', ['min' => 0]),
    ];
    $defaults = ['max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0, 'sort_order' => 0];
@endphp
<form method="POST" action="{{ $plan ? '/admin/plans/'.$plan->id : '/admin/plans' }}" class="ls-card ls-adm-form" onsubmit="LS.busy(this.querySelector('[type=submit]'))">
    @csrf
    @if ($plan) @method('PUT') @endif
    <div class="ls-card-body ls-stack">
        <div class="ls-adm-form-grid">
            @foreach ($fields as $f)
                <div class="ls-field">
                    <label class="ls-label" for="p-{{ $f['name'] }}">{{ $f['label'] }}@if (! empty($f['attrs']['required']))<span class="ls-req">*</span>@endif</label>
                    <input id="p-{{ $f['name'] }}" type="{{ $f['type'] }}" name="{{ $f['name'] }}" value="{{ old($f['name'], $plan?->{$f['name']} ?? ($defaults[$f['name']] ?? '')) }}"
                           class="ls-input {{ $errors->has($f['name']) ? 'is-invalid' : '' }}"
                           @foreach ($f['attrs'] as $k => $v) @if ($v === true) {{ $k }} @else {{ $k }}="{{ $v }}" @endif @endforeach>
                    @if ($f['hint'])<span class="ls-hint">{{ $f['hint'] }}</span>@endif
                    @error($f['name'])<span class="ls-error">{{ $message }}</span>@enderror
                </div>
            @endforeach
        </div>

        <fieldset class="ls-adm-fieldset">
            <legend class="ls-label">{{ __('app.plan.features') }}</legend>
            <p class="ls-hint" style="margin:0 0 var(--space-3)">{{ __('app.plan.features_hint') }}</p>
            <div class="ls-adm-checks">
                @foreach ($features as $feature)
                    <label class="ls-adm-check ls-adm-check--box"><input type="checkbox" name="features[]" value="{{ $feature->key }}" @checked($selected->contains($feature->key))> {{ $feature->name }}</label>
                @endforeach
            </div>
            @error('features')<span class="ls-error">{{ $message }}</span>@enderror
        </fieldset>
    </div>
    <div class="ls-dialog-foot">
        <a href="{{ $plan ? '/admin/plans/'.$plan->id : '/admin/plans' }}" class="ls-btn ls-btn--secondary">{{ __('app.common.cancel') }}</a>
        <button type="submit" class="ls-btn ls-btn--primary">{{ $plan ? __('app.plan.update_plan') : __('app.btn.add_plan') }}</button>
    </div>
</form>
@unless ($plan)
    <script>
        // Suggest a slug from the name until the slug is edited by hand.
        (function () {
            const name = document.getElementById('p-name'), slug = document.getElementById('p-slug');
            let touched = !!slug.value;
            slug.addEventListener('input', () => touched = true);
            name.addEventListener('input', () => { if (!touched) slug.value = name.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); });
        })();
    </script>
@endunless
