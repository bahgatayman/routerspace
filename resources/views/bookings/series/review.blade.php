@extends('layouts.app')

{{--
    Repeating booking — step 2 as a page (reached from the full booking form;
    the quick-booking modal shows the same step in place). The content and
    its behaviour are shared: bookings.series._review + _review-script.
    Expects: $v, $draft, $preview, $requestToken, $packages, $templates, $canSell.
--}}

@section('page-title', __('app.series.review_title'))

@section('content')
    <div class="ls-series-page">
        <x-ui.page-header :title="__('app.series.review_title')" :subtitle="__('app.series.review_subtitle')" />
        <ol class="ls-flow-steps" aria-label="{{ __('app.series.steps_label') }}">
            <li class="is-done"><span aria-hidden="true">1</span>{{ __('app.series.step_details') }}</li>
            <li aria-current="step"><span aria-hidden="true">2</span>{{ __('app.series.step_review') }}</li>
        </ol>
        <x-ui.flash />

        <div data-series-review-page>
            @include('bookings.series._review')
        </div>
    </div>

    @include('bookings.series._review-script')
    <script>
        // panel.js (LS.money) is deferred: it has run by DOMContentLoaded.
        document.addEventListener('DOMContentLoaded', () => {
            const box = document.querySelector('[data-series-review-page]');
            LSSeriesReview.init(box.querySelector('[data-series-review-root]'), { container: box });
        });
    </script>
@endsection
