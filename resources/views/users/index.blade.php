@extends('layouts.app')

@section('page-title', $owner->hasFeature('hotspot') ? __('app.user.hotspot_users') : __('app.common.members'))

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ $owner->hasFeature('hotspot') ? __('app.user.hotspot_users') : __('app.common.members') }}</h1>
        <a href="/users/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
            {{ __('app.btn.add_user') }}
        </a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>
    @endif

    <div class="mb-6 max-w-md relative" id="user-search-wrap">
        <input type="text" id="user-search-input" value="{{ $search }}" placeholder="{{ __('app.user.search_placeholder') }}"
               autocomplete="off"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 pe-9 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        <button type="button" id="user-search-clear" aria-label="{{ __('app.user.clear_search') }}"
                class="absolute inset-y-0 end-0 flex items-center px-3 text-gray-400 hover:text-gray-600" {{ $search === '' ? 'hidden' : '' }}>
            <x-ui.icon name="x" class="w-4 h-4" />
        </button>
    </div>

    <div id="user-table-wrap" aria-live="polite">
        @include('users._table', ['users' => $users, 'search' => $search])
    </div>

    <script>
    (function () {
        const input = document.getElementById('user-search-input');
        const clearBtn = document.getElementById('user-search-clear');
        const wrap = document.getElementById('user-table-wrap');
        let timer = null;
        let controller = null;

        function toggleClear() {
            clearBtn.hidden = input.value.length === 0;
        }

        // The shared row-link handler (layouts/app.blade.php) only wires rows
        // present at page load via a one-time querySelectorAll — it can't see
        // rows swapped in afterward, so a row injected by live search needs
        // the same click-to-navigate behavior re-applied here, scoped to just
        // this page's table rather than changing the shared handler itself.
        function wireRowLinks() {
            wrap.querySelectorAll('.row-link').forEach((row) => {
                const ignore = (event) => event.target.closest('a, button, form, input, select, label');
                row.addEventListener('click', (event) => {
                    if (ignore(event) || window.getSelection().toString()) return;
                    if (event.metaKey || event.ctrlKey) window.open(row.dataset.href, '_blank', 'noopener');
                    else window.location.href = row.dataset.href;
                });
                row.addEventListener('auxclick', (event) => {
                    if (event.button !== 1 || ignore(event)) return;
                    event.preventDefault();
                    window.open(row.dataset.href, '_blank', 'noopener');
                });
            });
        }

        function load(url, pushUrl) {
            if (controller) controller.abort();
            controller = new AbortController();
            wrap.setAttribute('aria-busy', 'true');
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                .then((r) => r.text())
                .then((html) => {
                    wrap.innerHTML = html;
                    wrap.removeAttribute('aria-busy');
                    wireRowLinks();
                    if (pushUrl) history.replaceState(null, '', url);
                })
                .catch((e) => { if (e.name !== 'AbortError') wrap.removeAttribute('aria-busy'); });
        }

        function searchUrl(term) {
            const params = new URLSearchParams();
            if (term) params.set('search', term);
            const qs = params.toString();
            return '/users' + (qs ? '?' + qs : '');
        }

        input.addEventListener('input', () => {
            toggleClear();
            clearTimeout(timer);
            // A new search always starts back at page 1 — the point being
            // searched from could be any page of the previous result set.
            timer = setTimeout(() => load(searchUrl(input.value.trim()), true), 250);
        });

        clearBtn.addEventListener('click', () => {
            input.value = '';
            toggleClear();
            clearTimeout(timer);
            load(searchUrl(''), true);
            input.focus();
        });

        // Pagination links rendered inside the swapped partial still point at
        // plain /users?... URLs — intercept them so paging also stays live
        // (no full reload) instead of only the initial search being AJAX.
        wrap.addEventListener('click', (e) => {
            const a = e.target.closest('a[href]');
            if (!a) return;
            const url = new URL(a.href, location.origin);
            if (url.origin !== location.origin || url.pathname !== '/users') return;
            e.preventDefault();
            load(url.pathname + url.search, true);
        });
    })();
    </script>
@endsection
