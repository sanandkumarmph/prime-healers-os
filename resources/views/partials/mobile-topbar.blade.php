@php
    $title = collect($breadcrumbItems ?? [])->last()['label'] ?? 'Prime Healers OS';
    $subtitle = $currentUser?->organization?->name ?? 'Rental & inventory operations';
    $mobileBackCrumb = collect($breadcrumbItems ?? [])
        ->slice(0, -1)
        ->reverse()
        ->first(fn ($crumb) => !empty($crumb['href']) && ($crumb['label'] ?? null) !== 'Home');

    if (!$mobileBackCrumb) {
        $mobileBackCrumb = collect($breadcrumbItems ?? [])
            ->first(fn ($crumb) => !empty($crumb['href']));
    }
@endphp

<header class="mobile-topbar">
    <a href="{{ route('dashboard') }}" class="mobile-brand" aria-label="Prime Healers OS home">
        <span class="mobile-brand-badge" aria-hidden="true">
            <x-application-logo class="mobile-brand-logo" />
        </span>
        <span class="mobile-brand-copy">
            <strong>Prime Healers OS</strong>
            <small>Rental, sales, and care operations</small>
        </span>
    </a>
    <button type="button" class="mobile-topbar-search" aria-label="Search shell">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
        <span>{{ $title === 'Prime Healers OS' ? 'Search customers, rentals, invoices...' : $title }}</span>
    </button>
    <button type="button" class="mobile-icon-button" data-mobile-more-open aria-label="Open mobile menu">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1.5"></circle><circle cx="12" cy="12" r="1.5"></circle><circle cx="12" cy="19" r="1.5"></circle></svg>
    </button>
</header>

@if(!empty($mobileBackCrumb['href']) && !empty($mobileBackCrumb['label']) && $title !== ($mobileBackCrumb['label'] ?? null))
    <div class="mobile-back-row">
        <a href="{{ $mobileBackCrumb['href'] }}" class="mobile-back-link" aria-label="Back to {{ $mobileBackCrumb['label'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
                <path d="M21 12H9"></path>
            </svg>
            <span>{{ $mobileBackCrumb['label'] }}</span>
        </a>
    </div>
@endif
