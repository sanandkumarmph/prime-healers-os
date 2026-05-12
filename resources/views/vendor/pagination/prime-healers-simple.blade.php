@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ $ariaLabel ?? __('Pagination Navigation') }}" class="ph-pagination {{ $shellClass ?? '' }}">
        <div class="ph-pagination-summary">
            {{ __('Page :page', ['page' => $paginator->currentPage()]) }}
        </div>

        <div class="ph-pagination-controls">
            @if ($paginator->onFirstPage())
                <span class="ph-page-link is-disabled" aria-disabled="true">{{ __('Previous') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="ph-page-link" rel="prev">{{ __('Previous') }}</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="ph-page-link" rel="next">{{ __('Next') }}</a>
            @else
                <span class="ph-page-link is-disabled" aria-disabled="true">{{ __('Next') }}</span>
            @endif
        </div>
    </nav>
@endif
