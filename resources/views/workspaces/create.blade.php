@extends('layouts.app')

@section('page-title', __('app.workspace.create_workspace'))

@php
    $f = fn (string $key, array $r = []) => __('app.workspace.form.'.$key, $r);
    $hasMaps = filled($mapsKey);
    // Contact/description stay folded unless they hold a value or an error.
    $moreOpen = collect(['description'])->contains(fn ($k) => filled(old($k)) || $errors->has($k));
    $nameValue = old('name', $suggestedName);
    $mapsText = [
        'searching' => $f('searching'), 'noResults' => $f('no_results'), 'searchError' => $f('search_error'),
        'unavailable' => $f('maps_unavailable'), 'locating' => $f('locating'), 'denied' => $f('location_denied'),
        'selected' => $f('location_selected'),
    ];
@endphp

@section('content')
    <div class="ls-ws-simple">
        <a href="{{ route('workspaces.index') }}" class="ls-ws-back">
            <x-ui.icon name="chevron-right" class="ls-ws-back-icon" />{{ __('app.btn.back_to_workspaces') }}
        </a>

        <x-ui.flash />

        <form method="POST" action="{{ route('workspaces.store') }}" class="ls-card ls-ws-card" id="workspace-form"
              onsubmit="if (window.LS) LS.busy(this.querySelector('[type=submit]'));">
            @csrf
            <header>
                <h1 class="ls-ws-title">{{ __('app.workspace.create_workspace') }}</h1>
                <p class="ls-ws-sub">{{ $f('subtitle') }}</p>
            </header>

            {{-- 1. Identity --}}
            <x-ui.input name="name" :label="__('app.workspace.workspace_name')" required maxlength="255" :value="$nameValue"
                        :placeholder="$f('name_ph')" :hint="$suggestedName && ! old('name') ? $f('name_from_space') : null"
                        :autofocus="! $suggestedName" />

            {{-- 2. Location (required address + city; the map pin itself is optional): search → map pin → editable fields --}}
            <fieldset class="ls-ws-location" aria-describedby="ws-loc-status">
                <legend class="ls-label">{{ $f('section_location') }}<span class="ls-req" aria-hidden="true">*</span></legend>

                @if ($hasMaps)
                    <div class="ls-ws-search" id="ws-search-box">
                        <div class="ls-search">
                            <x-ui.icon name="search" />
                            <input type="search" id="ws-place-search" class="ls-input" autocomplete="off" spellcheck="false"
                                   placeholder="{{ $f('search_ph') }}" aria-label="{{ $f('search_ph') }}"
                                   role="combobox" aria-expanded="false" aria-controls="ws-place-list" aria-autocomplete="list">
                        </div>
                        <ul id="ws-place-list" class="ls-ws-suggest" role="listbox" hidden></ul>
                    </div>
                    <div class="ls-ws-loc-tools">
                        <button type="button" class="ls-link ls-ws-tool" id="ws-use-location"><x-ui.icon name="pin" /><span>{{ $f('use_location') }}</span></button>
                        <button type="button" class="ls-link ls-ws-tool ls-ws-tool--muted" id="ws-clear-location" hidden><x-ui.icon name="x" /><span>{{ $f('remove_location') }}</span></button>
                    </div>
                    <div class="ls-ws-map-wrap" id="ws-map-wrap" hidden>
                        <div class="ls-ws-map" id="ws-map" role="img" aria-label="{{ $f('map_label') }}"></div>
                        <p class="ls-hint">{{ $f('pin_hint') }}</p>
                    </div>
                    <p class="ls-hint ls-ws-status" id="ws-loc-status" aria-live="polite"></p>
                @endif

                <x-ui.input name="address" :label="__('app.workspace.address')" required maxlength="500" autocomplete="street-address" :placeholder="$f('address_ph')" />
                <x-ui.input name="city" :label="__('app.workspace.city')" required maxlength="100" autocomplete="address-level2" :placeholder="$f('city_ph')" />

                <input type="hidden" name="latitude" id="ws-lat" value="{{ old('latitude') }}">
                <input type="hidden" name="longitude" id="ws-lng" value="{{ old('longitude') }}">
                <input type="hidden" name="place_id" id="ws-place-id" value="{{ old('place_id') }}">
                @if ($errors->hasAny(['latitude', 'longitude', 'place_id']))
                    <span class="ls-error"><x-ui.icon name="alert" />{{ $f('location_invalid') }}</span>
                @endif
            </fieldset>

            {{-- 3. Contact (required): an Egyptian mobile, validated server-side --}}
            <x-ui.input name="phone" type="tel" :label="__('app.workspace.form.mobile')" required maxlength="20" autocomplete="tel" inputmode="tel" dir="ltr"
                        :placeholder="$f('phone_ph')" :hint="$f('mobile_hint')" />

            {{-- 4. Description (optional) --}}
            <details class="ls-ws-more" @if ($moreOpen) open @endif>
                <summary><x-ui.icon name="plus" /><span>{{ $f('more') }}</span></summary>
                <div class="ls-stack">
                    <x-ui.input name="description" :label="__('app.workspace.description')" optional>
                        <textarea id="f-description" name="description" rows="2" maxlength="1000" class="ls-textarea {{ $errors->has('description') ? 'is-invalid' : '' }}">{{ old('description') }}</textarea>
                    </x-ui.input>
                </div>
            </details>

            <div class="ls-ws-actions">
                <x-ui.button variant="ghost" :href="route('workspaces.index')">{{ __('app.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" icon="plus">{{ __('app.btn.create_workspace') }}</x-ui.button>
            </div>
        </form>
    </div>

    @if ($hasMaps)
        <script>
        /*
         * Google Maps location picker. Loads Maps only when the owner starts
         * searching (or a location is already selected after a validation round
         * trip). Suggestions: Places API (New) AutocompleteSuggestion with a
         * session token; nothing is saved until the form is submitted. Address and
         * city stay editable — results are suggestions, not verified data.
         */
        (function () {
            const KEY = @json($mapsKey);
            const MAP_ID = @json($mapsMapId);
            const T = @json($mapsText);
            const LANG = document.documentElement.lang || 'en';
            const $ = (id) => document.getElementById(id);
            const search = $('ws-place-search'), list = $('ws-place-list'), status = $('ws-loc-status');
            const lat = $('ws-lat'), lng = $('ws-lng'), placeId = $('ws-place-id');
            const address = $('f-address'), city = $('f-city');
            let loader = null, map = null, marker = null, token = null, timer = null, items = [], active = -1, seq = 0;

            const say = (msg) => { status.textContent = msg || ''; };
            const unavailable = () => { $('ws-search-box').hidden = true; $('ws-use-location').hidden = true; say(T.unavailable); };
            window.gm_authFailure = unavailable; // invalid/restricted key

            function loadMaps() {
                if (loader) return loader;
                loader = new Promise((resolve, reject) => {
                    window.__lsMapsReady = () => resolve(window.google.maps);
                    const s = document.createElement('script');
                    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(KEY)}&v=weekly&loading=async&libraries=places,geocoding&language=${encodeURIComponent(LANG)}&callback=__lsMapsReady`;
                    s.async = true;
                    s.onerror = () => reject(new Error('load'));
                    document.head.appendChild(s);
                }).catch((e) => { unavailable(); throw e; });
                return loader;
            }

            function setOpen(open) {
                list.hidden = !open;
                search.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (!open) { active = -1; search.removeAttribute('aria-activedescendant'); }
            }

            function render() {
                list.innerHTML = '';
                items.forEach((s, i) => {
                    const p = s.placePrediction;
                    const li = document.createElement('li');
                    li.id = 'ws-opt-' + i; li.role = 'option'; li.className = 'ls-ws-option' + (i === active ? ' is-active' : '');
                    li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                    const main = document.createElement('b'); main.textContent = p.mainText ? p.mainText.text : p.text.text;
                    const sub = document.createElement('span'); sub.textContent = p.secondaryText ? p.secondaryText.text : '';
                    li.append(main, sub);
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(i); });
                    list.appendChild(li);
                });
                setOpen(items.length > 0);
                if (active >= 0) search.setAttribute('aria-activedescendant', 'ws-opt-' + active);
            }

            async function suggest() {
                const q = search.value.trim();
                if (q.length < 3) { items = []; setOpen(false); say(''); return; }
                const mine = ++seq;
                say(T.searching);
                try {
                    const maps = await loadMaps();
                    const { AutocompleteSuggestion, AutocompleteSessionToken } = await maps.importLibrary('places');
                    token = token || new AutocompleteSessionToken();
                    const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions({ input: q, sessionToken: token, language: LANG });
                    if (mine !== seq) return; // a newer keystroke already answered
                    items = suggestions.filter((s) => s.placePrediction);
                    active = -1;
                    render();
                    say(items.length ? '' : T.noResults);
                } catch (e) {
                    if (mine === seq) { items = []; setOpen(false); if (loader) say(T.searchError); }
                }
            }

            function cityFrom(components) {
                const pick = (type) => (components || []).find((c) => (c.types || []).includes(type));
                const c = pick('locality') || pick('postal_town') || pick('administrative_area_level_2') || pick('administrative_area_level_1');
                return c ? (c.longText || c.long_name || '') : '';
            }

            async function showOnMap(position) {
                const maps = await loadMaps();
                $('ws-map-wrap').hidden = false;
                $('ws-clear-location').hidden = false;
                if (!map) {
                    const { Map } = await maps.importLibrary('maps');
                    const { Marker } = await maps.importLibrary('marker');
                    map = new Map($('ws-map'), { center: position, zoom: 16, mapId: MAP_ID || undefined, streetViewControl: false, mapTypeControl: false, fullscreenControl: false });
                    marker = new Marker({ map, position, draggable: true });
                    // Dragging the pin adjusts the coordinates; it's no longer the exact Google place.
                    marker.addListener('dragend', () => {
                        const p = marker.getPosition();
                        lat.value = p.lat().toFixed(7); lng.value = p.lng().toFixed(7); placeId.value = '';
                    });
                } else {
                    map.setCenter(position); map.setZoom(16); marker.setPosition(position);
                }
                lat.value = Number(position.lat).toFixed(7);
                lng.value = Number(position.lng).toFixed(7);
            }

            async function choose(i) {
                const s = items[i];
                if (!s) return;
                setOpen(false);
                search.value = s.placePrediction.text.text;
                say(T.searching);
                try {
                    const place = s.placePrediction.toPlace();
                    await place.fetchFields({ fields: ['id', 'displayName', 'formattedAddress', 'location', 'addressComponents'] });
                    token = null; // a selection ends the billing session
                    address.value = place.formattedAddress || search.value;
                    const c = cityFrom(place.addressComponents);
                    if (c) city.value = c;
                    await showOnMap({ lat: place.location.lat(), lng: place.location.lng() });
                    placeId.value = place.id || '';
                    say(T.selected);
                } catch (e) { say(T.searchError); }
            }

            search.addEventListener('focus', () => { loadMaps().catch(() => {}); }, { once: true });
            search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(suggest, 250); });
            search.addEventListener('keydown', (e) => {
                if (list.hidden || !items.length) return;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    render();
                } else if (e.key === 'Enter') {
                    e.preventDefault(); choose(active < 0 ? 0 : active);
                } else if (e.key === 'Escape') { setOpen(false); }
            });
            search.addEventListener('blur', () => setTimeout(() => setOpen(false), 120));
            // Enter in the search box must never submit the whole form.
            search.form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target === search) e.preventDefault(); });

            // Device location only on an explicit click.
            $('ws-use-location').addEventListener('click', () => {
                if (!navigator.geolocation) { say(T.denied); return; }
                say(T.locating);
                navigator.geolocation.getCurrentPosition(async (pos) => {
                    const position = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                    try {
                        await showOnMap(position);
                        placeId.value = '';
                        const { Geocoder } = await window.google.maps.importLibrary('geocoding');
                        const { results } = await new Geocoder().geocode({ location: position, language: LANG });
                        if (results && results[0]) {
                            address.value = results[0].formatted_address;
                            const c = cityFrom(results[0].address_components);
                            if (c) city.value = c;
                        }
                        say(T.selected);
                    } catch (e) { say(lat.value ? T.selected : T.searchError); }
                }, () => say(T.denied), { enableHighAccuracy: true, timeout: 10000 });
            });

            $('ws-clear-location').addEventListener('click', () => {
                lat.value = ''; lng.value = ''; placeId.value = ''; search.value = '';
                $('ws-map-wrap').hidden = true; $('ws-clear-location').hidden = true; say('');
            });

            // Coming back from a validation error with a chosen point: show it again.
            if (lat.value && lng.value) showOnMap({ lat: Number(lat.value), lng: Number(lng.value) }).catch(() => {});
        })();
        </script>
    @endif
@endsection
