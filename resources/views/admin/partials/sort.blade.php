{{-- Sortable column header link. Expects $key, $label, $sort, $dir; keeps every other query param. --}}
@php
    $isOn = $sort === $key;
    $next = $isOn ? ($dir === 'asc' ? 'desc' : 'asc') : ($default ?? 'desc');
    $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
@endphp
<th scope="col" class="{{ $class ?? '' }}" @if ($isOn) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ $url }}" class="ls-sort {{ $isOn ? 'is-on' : '' }}">{{ $label }}<span aria-hidden="true">{{ $isOn ? ($dir === 'asc' ? '↑' : '↓') : '↕' }}</span></a>
</th>
