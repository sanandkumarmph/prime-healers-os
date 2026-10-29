<header class="phos-dashboard-header">
    <div class="phos-dashboard-heading">
        <div>
            <p>{{ now()->format('l, d F Y') }}</p>
            <h1>Executive command centre</h1>
            <p>Your business health, decisions and operating detail.</p>
        </div>
        <div class="rx-actions">
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
    <div class="phos-dashboard-scope">
        <span>{{ $dashboardOrderScope === 'mine' ? 'My orders' : 'Company view' }}</span>
        @if($city ?? '')<span>City: {{ $city }}</span>@endif
        @if(($fromDate ?? '') || ($toDate ?? ''))
            <span>Selected dates: {{ $fromDate ?: 'All earlier dates' }} – {{ $toDate ?: 'Present' }}</span>
        @endif
        <span>Each metric retains its existing reporting period and scope.</span>
    </div>
</header>
