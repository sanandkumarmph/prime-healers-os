@php
    $dashboardPeriodToday = now()->startOfDay();
    $dashboardPeriodPresets = [
        ['label' => 'Today', 'from' => $dashboardPeriodToday->toDateString(), 'to' => $dashboardPeriodToday->toDateString()],
        ['label' => 'Last 7 days', 'from' => $dashboardPeriodToday->copy()->subDays(6)->toDateString(), 'to' => $dashboardPeriodToday->toDateString()],
        ['label' => 'This month', 'from' => $dashboardPeriodToday->copy()->startOfMonth()->toDateString(), 'to' => $dashboardPeriodToday->toDateString()],
        ['label' => 'Last month', 'from' => $dashboardPeriodToday->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $dashboardPeriodToday->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ['label' => 'All dates', 'from' => null, 'to' => null],
    ];
    $dashboardPeriodLabel = 'Custom range';
    foreach ($dashboardPeriodPresets as $preset) {
        if (($fromDate ?? '') === ($preset['from'] ?? '') && ($toDate ?? '') === ($preset['to'] ?? '')) {
            $dashboardPeriodLabel = $preset['label'];
            break;
        }
    }
    $dashboardPeriodDate = function ($date) {
        if (!filled($date)) return '';
        try { return \Carbon\Carbon::parse($date)->format('d M Y'); }
        catch (\Throwable) { return (string) $date; }
    };
    $dashboardPeriodRange = filled($fromDate ?? '') || filled($toDate ?? '')
        ? ($dashboardPeriodDate($fromDate ?? '') ?: 'Any start') . ' – ' . ($dashboardPeriodDate($toDate ?? '') ?: 'Present')
        : 'All recorded activity';
    $activeDashboardFilterCount = collect(request()->only(['from_date', 'to_date', 'city', 'fulfilment_source', 'payment_status', 'search']))->filter(fn ($value) => filled($value))->count();
@endphp
<header class="phos-dashboard-header">
    <div class="phos-dashboard-heading">
        <div>
            <p>{{ now()->format('l, d F Y') }}</p>
            <h1>Executive command centre</h1>
            <p>Your business health, decisions and operating detail.</p>
        </div>
        <div class="rx-actions">
            <details class="phos-dashboard-filters" id="phos-dashboard-filters" data-dashboard-filters>
                <summary class="rx-btn-secondary phos-dashboard-filter-toggle" data-dashboard-filter-toggle
                         aria-label="Filter dashboard{{ $activeDashboardFilterCount ? ' (' . $activeDashboardFilterCount . ' active)' : '' }}"
                         aria-controls="phos-dashboard-filter-panel" title="Filters">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6.5 7.5V19l-3 1v-7.5L4 5Z"/></svg>
                    @if($activeDashboardFilterCount)<span class="phos-dashboard-filter-count" aria-hidden="true">{{ $activeDashboardFilterCount }}</span>@endif
                </summary>
                <div class="phos-dashboard-filter-panel" id="phos-dashboard-filter-panel">
                    <div class="phos-dashboard-filter-head">
                        <div><strong>Filters</strong><p>Choose dates, city, fulfilment and payment view.</p></div>
                        <button type="button" class="phos-dashboard-filter-close" aria-label="Close dashboard filters" data-dashboard-filter-close hidden>&times;</button>
                    </div>
                    <form method="GET" action="{{ $dashboardUrl }}" class="rx-form-grid" data-dashboard-filter-form>
                        <input type="hidden" name="dashboard_tab" value="{{ $activeDashboardTab }}">
                        <div class="dashboard-filter-grid">
                            <label class="rx-field">
                                <span class="rx-label">From Date</span>
                                <input type="date" name="from_date" value="{{ $fromDate ?? '' }}" class="rn-input" />
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">To Date</span>
                                <input type="date" name="to_date" value="{{ $toDate ?? '' }}" class="rn-input" />
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">City</span>
                                <select name="city" class="rn-input">
                                    <option value="">All Cities</option>
                                    @foreach($cities as $cityOption)
                                        <option value="{{ $cityOption }}" @selected(($city ?? null) === $cityOption)>{{ $cityOption }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Fulfilment</span>
                                <select name="fulfilment_source" class="rn-input">
                                    <option value="">All</option>
                                    <option value="in_house" @selected(request('fulfilment_source') === 'in_house')>In-house</option>
                                    <option value="vendor_supplied" @selected(request('fulfilment_source') === 'vendor_supplied')>Vendor supplied</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Payment Status</span>
                                <select name="payment_status" class="rn-input">
                                    <option value="">All</option>
                                    <option value="paid" @selected(request('payment_status') === 'paid')>Paid</option>
                                    <option value="partial" @selected(request('payment_status') === 'partial')>Partial</option>
                                    <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Unpaid</option>
                                </select>
                            </label>
                            <label class="rx-field">
                                <span class="rx-label">Search</span>
                                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Customer, rental, phone" class="rn-input" />
                            </label>
                        </div>

                        <div class="rx-actions">
                            <button type="submit" class="rx-btn">Apply</button>
                            <a href="{{ $safeRoute('dashboard') ?? $dashboardUrl }}" class="rx-btn-secondary" data-dashboard-filter-reset>Reset</a>
                        </div>
                    </form>
                </div>
            </details>
            @if($reportsIndexUrl)
                <a href="{{ $reportsIndexUrl }}" class="rx-btn-secondary">Detailed reports</a>
            @endif
            <details class="phos-dashboard-quick-actions">
                <summary class="rx-btn">Quick actions</summary>
                <div>
                    @foreach($dashboardQuickActions as $action)
                        <a href="{{ $action['href'] }}">{{ $action['label'] }}</a>
                    @endforeach
                    @if($dashboardQuickActions->isEmpty())
                        <span>No creation actions are enabled for your role.</span>
                    @endif
                </div>
            </details>
        </div>
    </div>
    <div class="phos-dashboard-scope">
        <details class="phos-dashboard-period" data-dashboard-period>
            <summary data-dashboard-period-toggle aria-label="Activity period: {{ $dashboardPeriodLabel }}, {{ $dashboardPeriodRange }}">
                {!! $dashboardIcon('rental') !!}
                <span><small>Activity period</small><strong>{{ $dashboardPeriodLabel }}</strong><span class="phos-dashboard-period-range">{{ $dashboardPeriodRange }}</span></span>
                <svg class="phos-dashboard-period-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
            </summary>
            <div class="phos-dashboard-period-menu">
                @foreach($dashboardPeriodPresets as $preset)
                    <a href="{{ $mergeDashboardQuery('dashboard', ['dashboard_tab' => $activeDashboardTab, 'date' => null, 'from_date' => $preset['from'], 'to_date' => $preset['to']]) }}"
                       data-dashboard-period-link @if($dashboardPeriodLabel === $preset['label']) aria-current="true" @endif>
                        <strong>{{ $preset['label'] }}</strong>
                        <span>{{ $preset['from'] ? $dashboardPeriodDate($preset['from']) . ' – ' . $dashboardPeriodDate($preset['to']) : 'All recorded activity' }}</span>
                    </a>
                @endforeach
                <button type="button" data-dashboard-custom-period hidden>Custom range…</button>
                <noscript><p>Choose Filters for a custom date range.</p></noscript>
            </div>
        </details>
        <span>{{ $dashboardOrderScope === 'mine' ? 'My orders' : 'Company view' }}</span>
        @if($city ?? '')<span>City: {{ $city }}</span>@endif
        <span>Each metric retains its existing reporting period and scope.</span>
    </div>
    <nav class="phos-dashboard-tabs" role="tablist" aria-label="Executive dashboard views">
        @foreach($dashboardTabs as $key => $tab)
            <a href="{{ $mergeDashboardQuery('dashboard', ['dashboard_tab' => $key]) }}"
               id="phos-tab-{{ $key }}"
               data-dashboard-tab="{{ $key }}"
               role="tab"
               aria-controls="phos-panel-{{ $key }}"
               aria-selected="{{ $activeDashboardTab === $key ? 'true' : 'false' }}"
               @class(['is-active' => $activeDashboardTab === $key])>
                {!! $dashboardIcon($tab['icon']) !!}
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
</header>
