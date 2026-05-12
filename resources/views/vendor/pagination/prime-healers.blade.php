@include('partials.ph-pagination', [
    'paginator' => $paginator,
    'summaryLabel' => $summaryLabel ?? 'results',
    'ariaLabel' => $ariaLabel ?? __('Pagination Navigation'),
    'shellClass' => $shellClass ?? '',
])
