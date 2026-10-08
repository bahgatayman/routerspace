{{--
    KPI card with an optional change vs the previous equal period.
    ['label', 'value' (formatted), 'change' (?float %), 'invert' (up is bad), 'help' (tooltip), 'tone', 'href', 'sub']
--}}
@php
    $change = $change ?? null;
    $good = $change === null ? null : (($change >= 0) xor ($invert ?? false));
    $tag = ! empty($href) ? 'a' : 'div';
@endphp
<{{ $tag }} @if (! empty($href)) href="{{ $href }}" @endif class="ls-akpi {{ ! empty($href) ? 'is-link' : '' }}" @if (! empty($help)) title="{{ $help }}" @endif>
    <span class="ls-akpi-label">{{ $label }}@if (! empty($help))<i class="ls-akpi-help" aria-hidden="true">?</i><span class="sr-only">. {{ $help }}</span>@endif</span>
    <b class="ls-akpi-value {{ ! empty($tone) ? 'is-'.$tone : '' }}">{{ $value }}</b>
    @if ($change !== null)
        <span class="ls-akpi-delta {{ $change == 0 ? 'is-flat' : ($good ? 'is-good' : 'is-bad') }}">
            <span aria-hidden="true">{{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '■') }}</span>
            {{ ($change > 0 ? '+' : '').number_format($change, 1) }}%
            <small>{{ __('app.admin_platform.vs_previous') }}</small>
        </span>
    @elseif (! empty($sub))
        <span class="ls-akpi-sub">{{ $sub }}</span>
    @endif
</{{ $tag }}>
