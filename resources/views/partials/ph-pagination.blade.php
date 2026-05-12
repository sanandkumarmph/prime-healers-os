@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
    $summaryLabel = $summaryLabel ?? 'results';
    $ariaLabel = $ariaLabel ?? 'Pagination';
    $shellClass = $shellClass ?? '';
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageWindow = collect(range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)));

    if ($pageWindow->first() > 1) {
        $pageWindow->prepend(1);
    }

    if ($pageWindow->count() > 1 && $pageWindow[1] > $pageWindow[0] + 1) {
        $pageWindow->splice(1, 0, ['gap-start']);
    }

    if ($pageWindow->last() < $lastPage) {
        if ($pageWindow->last() < $lastPage - 1) {
            $pageWindow->push('gap-end');
        }

        $pageWindow->push($lastPage);
    }
@endphp

@if($paginator->hasPages())
    <nav role="navigation" class="ph-pagination {{ $shellClass }}" aria-label="{{ $ariaLabel }}">
        <div class="ph-pagination-summary">
            Showing {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} of {{ $paginator->total() }} {{ $summaryLabel }}
        </div>

        <div class="ph-pagination-controls">
            @if($paginator->onFirstPage())
                <span class="ph-page-link is-disabled" aria-disabled="true">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="ph-page-link" rel="prev">Previous</a>
            @endif

            @foreach($pageWindow as $page)
                @if(is_string($page))
                    <span class="ph-page-link is-disabled" aria-hidden="true">…</span>
                @elseif($page === $currentPage)
                    <span class="ph-page-link is-active" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $paginator->url($page) }}" class="ph-page-link">{{ $page }}</a>
                @endif
            @endforeach

            @if($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="ph-page-link" rel="next">Next</a>
            @else
                <span class="ph-page-link is-disabled" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
