{{-- Design-system paginator (used as ->links('admin.partials.pager')). --}}
@if ($paginator->hasPages())
    <nav class="ls-pager" role="navigation" aria-label="{{ __('app.admin_platform.pagination') }}">
        @if ($paginator->onFirstPage())
            <span class="ls-pager-btn is-disabled" aria-disabled="true">{{ __('app.admin_platform.prev') }}</span>
        @else
            <a class="ls-pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('app.admin_platform.prev') }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="ls-pager-gap" aria-hidden="true">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="ls-pager-btn is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="ls-pager-btn" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="ls-pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('app.admin_platform.next') }}</a>
        @else
            <span class="ls-pager-btn is-disabled" aria-disabled="true">{{ __('app.admin_platform.next') }}</span>
        @endif
    </nav>
@endif
