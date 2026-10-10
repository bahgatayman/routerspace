{{--
    Chart card: Chart.js canvas + the same numbers as a table (toggle, and the
    fallback when the CDN is unreachable). Empty data → empty state, never a
    flat fake line.
    @include('admin.partials.chart', ['id' => 'rev', 'title' => '…', 'spec' => [...], 'note' => null, 'height' => 260])
--}}
@php
    $height = $height ?? 260;
    $total = collect($spec['datasets'])->flatMap(fn ($d) => $d['data'])->sum(fn ($v) => abs((float) $v));
    $isEmpty = $total == 0;
    $fmtCell = fn ($v) => ($spec['money'] ?? false) ? number_format((float) $v, 2) : number_format((float) $v);
@endphp
{{--
    The CDN tag is emitted inline via a shared @once id (not pushed to the
    'scripts' stack) so it's guaranteed available to anything later in
    document order — including the Owner dashboard's Products section, which
    loads Chart.js independently for its own bespoke charts and shares this
    same id so only one of the two ever actually loads the library.
--}}
@once('chartjs-cdn')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
@endonce
@once
    @push('scripts')
        <script src="/js/admin-charts.js?v={{ @filemtime(public_path('js/admin-charts.js')) }}" defer></script>
    @endpush
@endonce
<section class="ls-card ls-chart" id="{{ $id }}-box" aria-labelledby="{{ $id }}-title">
    <div class="ls-card-head">
        <div>
            <h2 class="ls-card-title" id="{{ $id }}-title">{{ $title }}</h2>
            @if (! empty($note))<p class="ls-chart-note">{{ $note }}</p>@endif
        </div>
        @unless ($isEmpty)
            <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm ls-chart-toggle" data-ls-chart-toggle="{{ $id }}-box" aria-pressed="false" title="{{ __('app.admin_platform.chart.table_view') }}">
                <span class="ls-chart-toggle-chart">{{ __('app.admin_platform.chart.table_view') }}</span>
                <span class="ls-chart-toggle-table">{{ __('app.admin_platform.chart.chart_view') }}</span>
            </button>
        @endunless
    </div>
    <div class="ls-card-body">
        @if ($isEmpty)
            <div class="ls-chart-empty">
                <x-ui.icon name="alert" />
                <span>{{ $empty ?? __('app.admin_platform.chart.empty') }}</span>
            </div>
        @else
            <div class="ls-chart-canvas" style="height: {{ $height }}px">
                <canvas data-ls-chart="{{ $id }}" role="img" aria-label="{{ $title }}" data-click-hint="{{ ! empty($spec['links']) ? __('app.admin_platform.chart.click_hint') : '' }}"></canvas>
                <div class="ls-skeleton ls-chart-skeleton" aria-hidden="true"></div>
            </div>
            <script type="application/json" id="{{ $id }}-spec">@json($spec)</script>
            <p class="ls-chart-failed">{{ __('app.admin_platform.chart.failed') }}</p>
            <div class="ls-table-wrap ls-chart-table">
                <table class="ls-table">
                    <thead><tr>
                        <th scope="col">{{ $spec['axis'] ?? '' }}</th>
                        @foreach ($spec['datasets'] as $d)<th scope="col" class="is-num">{{ $d['label'] }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($spec['labels'] as $i => $label)
                            <tr>
                                <th scope="row" style="font-weight:400">
                                    @if (! empty($spec['links'][$i]))<a href="{{ $spec['links'][$i] }}" class="ls-link">{{ $label }}</a>@else{{ $label }}@endif
                                </th>
                                @foreach ($spec['datasets'] as $d)<td class="is-num">{{ $fmtCell($d['data'][$i] ?? 0) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
