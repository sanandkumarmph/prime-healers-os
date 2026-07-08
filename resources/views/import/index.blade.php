@extends('layouts.app')

@section('breadcrumbs')
    <span class="import-breadcrumb-suppressed" aria-hidden="true" style="display:none!important"></span>
@endsection

@section('content')
@php
    $categoryDefinitions = [
        'foundation' => [
            'title' => 'Foundation Data',
            'subtitle' => 'Import master records first.',
            'icon' => 'FD',
            'keys' => ['products', 'customers', 'vendors', 'staff'],
        ],
        'inventory' => [
            'title' => 'Inventory',
            'subtitle' => 'Load stock and physical units after setup.',
            'icon' => 'IN',
            'keys' => ['opening-balances', 'assets'],
        ],
        'transactions' => [
            'title' => 'Transactions',
            'subtitle' => 'Import orders and collections after prerequisites.',
            'icon' => 'TX',
            'keys' => ['rentals', 'sales', 'payments'],
        ],
    ];

    $moduleMeta = [
        'customers' => ['name' => 'Customers', 'description' => 'Import customers and partner-linked contacts.', 'icon' => 'CU', 'chips' => ['GST', 'City', 'Partner']],
        'products' => ['name' => 'Product Master', 'description' => 'Import catalog, pricing, GST, and stock mode.', 'icon' => 'PM', 'chips' => ['GST', 'Rental', 'Sale', 'Stock']],
        'vendors' => ['name' => 'Vendors', 'description' => 'Import suppliers and fulfilment partners.', 'icon' => 'VE', 'chips' => ['City', 'Vendor']],
        'cities' => ['name' => 'Cities', 'description' => 'Import city and regional mappings.', 'icon' => 'CI', 'chips' => ['Multi-city']],
        'staff' => ['name' => 'Staff', 'description' => 'Import staff profiles and assignment roles.', 'icon' => 'ST', 'chips' => ['City', 'Role']],
        'assets' => ['name' => 'Assets', 'description' => 'Import rental assets, serials, barcodes, and custody.', 'icon' => 'AS', 'chips' => ['Serial', 'Warehouse', 'City']],
        'opening-balances' => ['name' => 'Opening Balance', 'description' => 'Import legacy customer balances for finance follow-up.', 'icon' => 'OB', 'chips' => ['Stock', 'Billing']],
        'stock-history' => ['name' => 'Stock History', 'description' => 'Import historical inventory movements.', 'icon' => 'SH', 'chips' => ['History', 'Warehouse']],
        'rentals' => ['name' => 'Rentals', 'description' => 'Import rental bookings, items, deposits, and status.', 'icon' => 'RE', 'chips' => ['Rental', 'Assets', 'Billing']],
        'sales' => ['name' => 'Sales', 'description' => 'Import sale orders, line items, and fulfilment.', 'icon' => 'SA', 'chips' => ['Sale', 'Items', 'Billing']],
        'payments' => ['name' => 'Payments', 'description' => 'Import customer collections and receipts.', 'icon' => 'PY', 'chips' => ['Payment', 'Receipts']],
    ];

    $cardsByKey = collect($cards)->keyBy('key');
    $setup = $setup ?? ['phases' => collect(), 'cards' => [], 'overall' => ['complete' => 0, 'total' => $cardsByKey->count(), 'percent' => 0], 'next' => null];
    $setupPhases = collect($setup['phases'] ?? [])->keyBy('key');
    $sections = collect($categoryDefinitions)->map(function ($definition, $sectionKey) use ($cardsByKey, $moduleMeta, $setupPhases) {
        $modules = collect($definition['keys'])
            ->filter(fn ($key) => $cardsByKey->has($key))
            ->map(function ($key) use ($cardsByKey, $moduleMeta) {
                $card = $cardsByKey->get($key);
                $meta = $moduleMeta[$key] ?? [
                    'name' => $card['label'] ?? str($key)->headline()->toString(),
                    'description' => $card['note'] ?? 'Import validated PHOS data.',
                    'icon' => strtoupper(substr($key, 0, 2)),
                    'chips' => ['Template'],
                ];

                return $card + ['meta' => $meta];
            })
            ->values();

        return $definition + [
            'key' => $sectionKey,
            'setup' => $setupPhases->get($sectionKey),
            'modules' => $modules,
            'search_text' => $modules->map(function ($card) {
                return collect([
                    $card['label'] ?? '',
                    $card['key'] ?? '',
                    data_get($card, 'meta.name'),
                    data_get($card, 'meta.description'),
                    implode(' ', data_get($card, 'meta.chips', [])),
                ])->implode(' ');
            })->implode(' '),
        ];
    })->filter(fn ($section) => $section['modules']->isNotEmpty())->values();

    $recentImports = collect($recentImports ?? []);
    $totalModules = $sections->sum(fn ($section) => $section['modules']->count());
    $overallSetup = $setup['overall'] ?? ['complete' => 0, 'total' => $totalModules, 'percent' => 0];
    $nextImport = $setup['next'] ?? null;
@endphp

<div class="import-center" x-data="{
        query: '',
        openFieldsKey: null,
        assistantOpen: false,
        matches(text) { return !this.query.trim() || String(text || '').toLowerCase().includes(this.query.trim().toLowerCase()); },
    }">
    <header class="ic-header">
        <div>
            <nav class="ic-breadcrumb" aria-label="Breadcrumb"><span>Settings</span><span>/</span><strong>Data Import</strong></nav>
            <h1>Data Import</h1>
            <p>Bulk import data into PHOS using validated templates.</p>
        </div>
        <label class="ic-top-search" aria-label="Search import types">
            <span aria-hidden="true">S</span>
            <input type="search" x-model.debounce.120ms="query" placeholder="Search import types...">
        </label>
    </header>

    <section class="ic-trust-strip" aria-label="Import safeguards">
        <article><span>SI</span><div><strong>Secure Import</strong><small>Template-based uploads</small></div></article>
        <article><span>AV</span><div><strong>Auto Validation</strong><small>Preview before import</small></div></article>
        <article><span>MC</span><div><strong>Multi-City Aware</strong><small>City-specific mapping</small></div></article>
    </section>

    <section class="ic-setup-progress" aria-label="Import setup progress">
        <div class="ic-progress-main">
            <div>
                <span>Setup progress</span>
                <strong>{{ $overallSetup['complete'] ?? 0 }}/{{ $overallSetup['total'] ?? $totalModules }} complete</strong>
            </div>
            <div class="ic-progress-track"><span style="width: {{ $overallSetup['percent'] ?? 0 }}%"></span></div>
            <b>{{ $overallSetup['percent'] ?? 0 }}%</b>
        </div>
        <div class="ic-phase-strip">
            @foreach(collect($setup['phases'] ?? []) as $phase)
                <article>
                    <span>{{ $phase['icon'] ?? 'IM' }}</span>
                    <div><strong>{{ $phase['title'] }}</strong><small>{{ $phase['complete'] }}/{{ $phase['total'] }} complete</small></div>
                    <em>{{ $phase['percent'] }}%</em>
                </article>
            @endforeach
        </div>
        @if($nextImport)
            <div class="ic-next-step">
                <span>Next recommended</span>
                <strong>{{ str($nextImport['key'])->replace('-', ' ')->title() }}</strong>
                <small>{{ $nextImport['status_label'] ?? 'Ready to Import' }}</small>
            </div>
        @endif
    </section>

    <div class="ic-layout">
        <main class="ic-main">
            <div class="ic-search-card">
                <label class="ic-search" aria-label="Search import modules">
                    <span aria-hidden="true">S</span>
                    <input type="search" x-model.debounce.120ms="query" placeholder="Search import modules...">
                </label>
                <button type="button" class="ic-how" @click="assistantOpen = !assistantOpen" :aria-expanded="assistantOpen.toString()">How it works</button>
            </div>

            <section class="ic-assistant-mobile" x-show="assistantOpen" x-transition>
                <h2>Import Assistant</h2>
                <ol>
                    <li>Download the correct template.</li>
                    <li>Follow column instructions.</li>
                    <li>Upload and validate the file.</li>
                    <li>Preview, confirm, and import.</li>
                </ol>
            </section>

            <div class="ic-sections">
                @foreach($sections as $section)
                    <section class="ic-section" data-section-text="{{ strtolower($section['title'] . ' ' . $section['subtitle'] . ' ' . $section['search_text']) }}" x-show="matches($el.dataset.sectionText)">
                        <details class="ic-section-details" open>
                            <summary>
                                <span class="ic-section-icon">{{ $section['icon'] }}</span>
                                <span class="ic-section-title-wrap">
                                    <strong>{{ $section['title'] }}</strong>
                                    <small>{{ $section['subtitle'] }}</small>
                                </span>
                                <span class="ic-count">{{ data_get($section, 'setup.complete', 0) }}/{{ data_get($section, 'setup.total', $section['modules']->count()) }} complete</span>
                            </summary>

                            <div class="ic-card-grid">
                                @foreach($section['modules'] as $card)
                                    @php
                                        $key = $card['key'];
                                        $meta = $card['meta'];
                                        $setupState = $card['setup'] ?? ['status' => 'ready', 'status_label' => 'Ready to Import', 'record_count' => 0, 'missing' => []];
                                        $status = $setupState['status'] ?? 'ready';
                                        $recordCount = (int) ($setupState['record_count'] ?? 0);
                                        $missingDependencies = collect($setupState['missing'] ?? []);
                                        $canImport = ($card['upload_available'] && $card['upload_href'] && $status !== 'blocked');
                                        $fieldCount = count($card['fields'] ?? []);
                                        $modalId = 'fields-modal-' . $key;
                                        $cardSearch = strtolower(collect([
                                            $section['title'],
                                            $card['label'] ?? '',
                                            $key,
                                            $meta['name'] ?? '',
                                            $meta['description'] ?? '',
                                            implode(' ', $meta['chips'] ?? []),
                                            $setupState['status_label'] ?? '',
                                            $missingDependencies->pluck('label')->implode(' '),
                                        ])->implode(' '));
                                    @endphp
                                    <article class="ic-import-card ic-import-card-{{ $status }}" data-card-text="{{ $cardSearch }}" x-show="matches($el.dataset.cardText)">
                                        <div class="ic-card-head">
                                            <span class="ic-module-icon">{{ $meta['icon'] }}</span>
                                            <div>
                                                <h3>{{ $meta['name'] }}</h3>
                                                <p>{{ $meta['description'] }}</p>
                                            </div>
                                            <button type="button" class="ic-info" @click="openFieldsKey = '{{ $key }}'" aria-label="View {{ $meta['name'] }} fields" title="View fields">i</button>
                                        </div>

                                        @if($status === 'completed')
                                            <div class="ic-last-import ic-status-completed"><span class="ic-status-dot"></span>Completed · {{ number_format($recordCount) }} records</div>
                                        @elseif($status === 'blocked')
                                            <div class="ic-last-import ic-status-blocked"><span class="ic-status-dot"></span>Blocked</div>
                                        @else
                                            <div class="ic-last-import ic-status-ready"><span class="ic-status-dot"></span>Ready to Import</div>
                                        @endif

                                        <div class="ic-chip-row">
                                            @foreach(array_slice($meta['chips'] ?? ['Template'], 0, 5) as $chip)
                                                <span>{{ $chip }}</span>
                                            @endforeach
                                        </div>

                                        @if($missingDependencies->isNotEmpty())
                                            <div class="ic-missing">
                                                <strong>Complete these first</strong>
                                                <ul>
                                                    @foreach($missingDependencies as $dependency)
                                                        <li>
                                                            @if(!empty($dependency['href']))
                                                                <a href="{{ $dependency['href'] }}">{{ $dependency['label'] }}</a>
                                                            @else
                                                                <span>{{ $dependency['label'] }}</span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        <div class="ic-card-actions">
                                            <a href="{{ $card['download_href'] }}" class="ic-btn ic-btn-muted">Download Template</a>
                                            @if($canImport)
                                                <a href="{{ $card['upload_href'] }}" class="ic-btn ic-btn-primary">Import</a>
                                            @elseif($status === 'blocked')
                                                <button type="button" class="ic-btn ic-btn-disabled" disabled title="Complete prerequisites first">Blocked</button>
                                            @else
                                                <button type="button" class="ic-btn ic-btn-disabled" disabled>Import</button>
                                            @endif
                                        </div>

                                        @if($status === 'blocked')
                                            <div class="ic-coming-soon">{{ $setupState['download_note'] ?? 'Template download is available now; import unlocks after prerequisites.' }}</div>
                                        @elseif(!($card['upload_available'] && $card['upload_href']))
                                            <div class="ic-coming-soon">Upload flow not active yet.</div>
                                        @endif

                                        <div
                                            x-cloak
                                            x-show="openFieldsKey === '{{ $key }}'"
                                            class="ic-modal"
                                            id="{{ $modalId }}"
                                            role="dialog"
                                            aria-modal="true"
                                            aria-labelledby="{{ $modalId }}-title"
                                            @keydown.escape.window="openFieldsKey = null"
                                        >
                                            <div class="ic-modal-backdrop" @click="openFieldsKey = null"></div>
                                            <div class="ic-modal-panel">
                                                <div class="ic-modal-head">
                                                    <div>
                                                        <span class="ic-modal-kicker">Template fields</span>
                                                        <h2 id="{{ $modalId }}-title">{{ $meta['name'] }}</h2>
                                                        <p>{{ number_format($fieldCount) }} fields are included in the current workbook template.</p>
                                                    </div>
                                                    <button type="button" class="ic-modal-close" @click="openFieldsKey = null" aria-label="Close fields panel">Close</button>
                                                </div>                                                @php($fieldDetails = $card['field_details'] ?? [])
                                                <div class="ic-field-guidance">
                                                    <strong>Guidance</strong>
                                                    <span>Required fields, accepted values, and sample rows are included in the downloaded workbook.</span>
                                                </div>
                                                @if(!empty($fieldDetails))
                                                    <div class="ic-fields-list">
                                                        @foreach($fieldDetails as $field)
                                                            <article>
                                                                <div>
                                                                    <strong>{{ $field['label'] ?? $field['key'] ?? 'Field' }}</strong>
                                                                    @if(!empty($field['required']))
                                                                        <span>Required</span>
                                                                    @endif
                                                                </div>
                                                                @if(!empty($field['sample']))
                                                                    <small><b>Sample:</b> {{ $field['sample'] }}</small>
                                                                @endif
                                                                @if(!empty($field['accepted_values']))
                                                                    <small><b>Accepted:</b> {{ $field['accepted_values'] }}</small>
                                                                @endif
                                                                @if(!empty($field['description']))
                                                                    <p>{{ $field['description'] }}</p>
                                                                @endif
                                                            </article>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <div class="ic-fields-grid">
                                                        @forelse($card['fields'] as $field)
                                                            <span>{{ $field }}</span>
                                                        @empty
                                                            <span>No field list available.</span>
                                                        @endforelse
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </details>
                    </section>
                @endforeach
            </div>

            <div class="ic-empty" x-show="query.trim() && !Array.from(document.querySelectorAll('.ic-import-card')).some(card => matches(card.dataset.cardText))">
                No import modules match your search.
            </div>
        </main>

        <aside class="ic-side">
            @if($recentImports->isNotEmpty())
                <section class="ic-side-card">
                    <div class="ic-side-head"><h2>Recent Imports</h2><a href="{{ route('imports.index') }}">View all</a></div>
                    <div class="ic-recent-list">
                        @foreach($recentImports->take(5) as $recent)
                            <div class="ic-recent-item">
                                <strong>{{ $recent['module'] ?? 'Import' }}</strong>
                                <span>{{ $recent['uploaded_at'] ?? 'Recently' }}</span>
                                <small>{{ $recent['rows'] ?? 0 }} rows</small>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="ic-side-card ic-assistant-card">
                <h2>Import Assistant</h2>
                <ol>
                    <li>Download the correct template.</li>
                    <li>Follow the column instructions.</li>
                    <li>Upload and validate your file.</li>
                    <li>Preview and confirm data.</li>
                    <li>Import with confidence.</li>
                </ol>
            </section>

            <section class="ic-help-card">
                <h2>Need help with import?</h2>
                <p>Use the workbook guidance sheet before uploading.</p>
            </section>
        </aside>
    </div>
</div>

<style>
[x-cloak]{display:none!important}
.import-center{max-width:1500px;margin:-4px auto 0;padding-bottom:80px;color:#0f172a}.ic-header{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:10px}.ic-breadcrumb{display:flex;gap:8px;align-items:center;color:#64748b;font-size:12px;font-weight:800;margin-bottom:6px}.ic-header h1{margin:0;font-size:25px;line-height:1.08;letter-spacing:-.035em}.ic-header p{margin:4px 0 0;color:#64748b;font-size:14px}.ic-top-search,.ic-search{display:flex;align-items:center;gap:8px;height:42px;border:1px solid #dbe4f0;background:#fff;border-radius:14px;padding:0 12px;box-sizing:border-box}.ic-top-search{width:min(340px,100%)}.ic-top-search span,.ic-search span{width:22px;height:22px;border-radius:8px;background:#eef2ff;color:#4338ca;display:grid;place-items:center;font-size:11px;font-weight:900}.ic-top-search input,.ic-search input{border:0;outline:0;min-width:0;width:100%;font-size:13px;background:transparent}.ic-trust-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.ic-trust-strip article{display:flex;gap:10px;align-items:center;border:1px solid #dbe4f0;background:#fff;border-radius:14px;padding:10px 12px;min-height:58px}.ic-trust-strip span,.ic-module-icon,.ic-section-icon{display:grid;place-items:center;border-radius:12px;background:#eef2ff;color:#4338ca;font-weight:900;font-size:11px;flex:0 0 auto}.ic-trust-strip span{width:34px;height:34px}.ic-trust-strip strong{display:block;font-size:13px}.ic-trust-strip small{color:#64748b;font-size:12px}.ic-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px}.ic-main,.ic-side{min-width:0}.ic-search-card{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #dbe4f0;background:#fff;border-radius:16px;padding:12px;margin-bottom:12px}.ic-search{flex:1}.ic-how{height:38px;border:0;background:#eef2ff;color:#4338ca;border-radius:12px;padding:0 12px;font-weight:900;font-size:12px}.ic-sections{display:grid;gap:12px}.ic-section{border:1px solid #dbe4f0;background:#fff;border-radius:18px;overflow:hidden}.ic-section-details>summary{display:flex;align-items:center;gap:12px;padding:13px 14px;list-style:none;cursor:pointer;border-bottom:1px solid #edf2f7}.ic-section-details>summary::-webkit-details-marker{display:none}.ic-section-icon{width:34px;height:34px}.ic-section-title-wrap{display:flex;align-items:baseline;gap:8px;min-width:0;flex:1}.ic-section-title-wrap strong{font-size:16px}.ic-section-title-wrap small{color:#64748b;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ic-count{display:inline-flex;align-items:center;height:26px;padding:0 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:900}.ic-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:12px}.ic-import-card{display:flex;flex-direction:column;gap:10px;border:1px solid #dbe4f0;background:#fff;border-radius:16px;padding:12px;min-height:196px;box-shadow:0 8px 22px rgba(15,23,42,.035)}.ic-card-head{display:grid;grid-template-columns:42px minmax(0,1fr) 30px;gap:10px;align-items:start}.ic-module-icon{width:42px;height:42px;background:#eef2ff}.ic-card-head h3{margin:0;font-size:15px;line-height:1.2}.ic-card-head p{margin:4px 0 0;color:#64748b;font-size:12px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.ic-info{width:30px;height:30px;border:1px solid #dbe4f0;background:#fff;border-radius:10px;color:#64748b;font-weight:900}.ic-last-import{display:flex;align-items:center;gap:6px;width:max-content;max-width:100%;height:24px;padding:0 8px;border-radius:999px;background:#f0fdf4;color:#166534;font-size:11px;font-weight:800}.ic-status-dot{width:6px;height:6px;border-radius:999px;background:#22c55e}.ic-chip-row{display:flex;flex-wrap:wrap;gap:6px}.ic-chip-row span{height:24px;padding:0 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:800;display:inline-flex;align-items:center}.ic-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:auto}.ic-btn{height:38px;border-radius:11px;font-size:13px;font-weight:900;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0}.ic-btn-muted{background:#fff;color:#0f172a}.ic-btn-primary{background:#4f46e5;border-color:#4f46e5;color:#fff}.ic-btn-disabled{background:#f8fafc;color:#94a3b8;cursor:not-allowed}.ic-coming-soon{font-size:11px;color:#64748b;text-align:center}.ic-side{display:grid;gap:12px;align-self:start;position:sticky;top:14px}.ic-side-card,.ic-help-card,.ic-assistant-mobile{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:16px}.ic-side-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.ic-side h2,.ic-assistant-mobile h2,.ic-help-card h2{margin:0 0 10px;font-size:16px}.ic-side-head a{font-size:12px;font-weight:900;color:#4f46e5;text-decoration:none}.ic-assistant-card ol,.ic-assistant-mobile ol{margin:0;padding-left:18px;color:#475569;font-size:13px;line-height:1.9}.ic-help-card{background:#f8f7ff}.ic-help-card p{margin:0;color:#64748b;font-size:13px}.ic-recent-list{display:grid;gap:10px}.ic-recent-item{display:grid;gap:2px;padding:10px;border:1px solid #edf2f7;border-radius:12px}.ic-recent-item strong{font-size:13px}.ic-recent-item span,.ic-recent-item small{font-size:12px;color:#64748b}.ic-empty{border:1px dashed #cbd5e1;border-radius:16px;padding:18px;color:#64748b;background:#fff;text-align:center;margin-top:12px}.ic-assistant-mobile{display:none;margin-bottom:12px}.ic-modal{position:fixed;inset:0;z-index:100;display:grid;place-items:center;padding:18px}.ic-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.48);backdrop-filter:blur(3px)}.ic-modal-panel{position:relative;z-index:1;width:min(760px,100%);max-height:min(82vh,760px);overflow:auto;border:1px solid #dbe4f0;background:#fff;border-radius:22px;box-shadow:0 30px 80px rgba(15,23,42,.24);padding:18px}.ic-modal-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.ic-modal-kicker{color:#4f46e5;text-transform:uppercase;letter-spacing:.08em;font-size:11px;font-weight:900}.ic-modal h2{margin:6px 0;font-size:22px}.ic-modal p{margin:0;color:#64748b;font-size:13px}.ic-modal-close{height:36px;border:1px solid #dbe4f0;background:#fff;border-radius:11px;padding:0 12px;font-weight:900}.ic-field-guidance{display:grid;gap:4px;margin:14px 0;padding:12px;border-radius:14px;background:#f8fafc;border:1px solid #edf2f7;color:#475569;font-size:13px}.ic-field-guidance strong{color:#0f172a}.ic-fields-list{display:grid;gap:8px}.ic-fields-list article{display:grid;gap:5px;padding:10px;border:1px solid #e2e8f0;background:#fff;border-radius:12px}.ic-fields-list article>div{display:flex;align-items:center;justify-content:space-between;gap:10px}.ic-fields-list strong{font-size:13px}.ic-fields-list span{height:22px;padding:0 8px;border-radius:999px;background:#dcfce7;color:#166534;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.ic-fields-list small{font-size:12px;color:#475569}.ic-fields-list p{margin:0;color:#64748b;font-size:12px;line-height:1.35}.ic-fields-list b{color:#334155}.ic-fields-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px}.ic-fields-grid span{padding:9px 10px;border:1px solid #e2e8f0;background:#fff;border-radius:12px;font-size:12px;font-weight:800;color:#334155}.ic-setup-progress{display:grid;gap:10px;margin:0 0 12px;border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:12px}.ic-progress-main{display:grid;grid-template-columns:minmax(180px,260px) 1fr auto;gap:12px;align-items:center}.ic-progress-main span,.ic-next-step span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.ic-progress-main strong{display:block;margin-top:2px;font-size:16px}.ic-progress-main b{font-size:14px;color:#4338ca}.ic-progress-track{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}.ic-progress-track span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#22c55e,#4f46e5)}.ic-phase-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.ic-phase-strip article{display:flex;align-items:center;gap:10px;border:1px solid #edf2f7;background:#f8fafc;border-radius:14px;padding:10px}.ic-phase-strip span{width:32px;height:32px;border-radius:11px;background:#eef2ff;color:#4338ca;display:grid;place-items:center;font-size:11px;font-weight:900}.ic-phase-strip strong{display:block;font-size:13px}.ic-phase-strip small{color:#64748b;font-size:12px}.ic-phase-strip em{margin-left:auto;font-style:normal;font-size:12px;font-weight:900;color:#16a34a}.ic-next-step{display:flex;align-items:center;gap:10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:10px 12px}.ic-next-step strong{font-size:14px}.ic-next-step small{margin-left:auto;color:#1d4ed8;font-size:12px;font-weight:900}.ic-import-card-ready{border-color:#bfdbfe}.ic-import-card-completed{border-color:#bbf7d0}.ic-import-card-blocked{background:#f8fafc}.ic-status-ready{background:#eff6ff;color:#1d4ed8}.ic-status-ready .ic-status-dot{background:#3b82f6}.ic-status-completed{background:#f0fdf4;color:#166534}.ic-status-blocked{background:#f1f5f9;color:#475569}.ic-status-blocked .ic-status-dot{background:#94a3b8}.ic-missing{display:grid;gap:6px;border:1px dashed #cbd5e1;background:#f8fafc;border-radius:12px;padding:9px 10px}.ic-missing strong{font-size:12px;color:#334155}.ic-missing ul{margin:0;padding-left:16px;color:#64748b;font-size:12px;line-height:1.45}.ic-missing a{color:#4f46e5;font-weight:800;text-decoration:none}@media(max-width:1200px){.ic-layout{grid-template-columns:1fr}.ic-side{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}.ic-card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.import-center{padding-bottom:120px}.ic-header{display:grid}.ic-header h1{font-size:24px}.ic-top-search{width:100%}.ic-trust-strip{grid-template-columns:1fr}.ic-setup-progress{padding:10px}.ic-progress-main{grid-template-columns:1fr auto}.ic-progress-track{grid-column:1/-1;grid-row:2}.ic-phase-strip{grid-template-columns:1fr}.ic-next-step{display:grid;gap:4px}.ic-next-step small{margin-left:0}.ic-search-card{display:grid}.ic-how{width:100%}.ic-assistant-mobile{display:block}.ic-side{grid-template-columns:1fr}.ic-assistant-card{display:none}.ic-section-title-wrap{display:grid;gap:2px}.ic-section-title-wrap small{white-space:normal}.ic-card-grid{grid-template-columns:1fr}.ic-import-card{min-height:auto}.ic-card-actions{grid-template-columns:1fr 1fr}.ic-section-details>summary{align-items:flex-start}.ic-count{margin-left:auto}.ic-modal{padding:10px}.ic-modal-panel{max-height:88vh;border-radius:18px}.ic-modal-head{display:grid}.ic-fields-grid{grid-template-columns:1fr}.ic-trust-strip article{min-height:50px}.ic-trust-strip small{display:none}}@media(max-width:420px){.ic-card-actions{grid-template-columns:1fr}.ic-btn{height:40px}}
</style>
@endsection
