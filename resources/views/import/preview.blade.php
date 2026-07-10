@extends('layouts.app')

@section('content')
@php
    $validRows = collect($preview['valid_rows'] ?? []);
    $invalidRows = collect($preview['invalid_rows'] ?? []);
    $assetPrecheck = $preview['asset_precheck']['untracked_products'] ?? [];
    $alreadyImported = !empty($preview['imported_at']);
    $executionPreviewInvalidRows = collect($lastResult['preview_invalid_rows'] ?? []);
    $executionSkippedRows = collect($lastResult['skipped_rows'] ?? []);
    $executionFailedRows = collect($lastResult['failed_rows'] ?? []);
    $reasonGroups = collect($lastResult['reason_groups'] ?? []);
    $customerEntityMeta = [
        'direct_customer' => ['label' => 'Direct Customer', 'plural' => 'Direct Customers', 'class' => 'is-direct'],
        'business_partner' => ['label' => 'Business Partner', 'plural' => 'Business Partners', 'class' => 'is-partner'],
        'actual_client' => ['label' => 'Actual Client', 'plural' => 'Actual Clients', 'class' => 'is-client'],
    ];
    $customerEntityKey = function (array $row): string {
        return (string) data_get($row, 'payload.import_entity', data_get($row, 'payload.customer_type', 'direct_customer'));
    };
    $runtimeBlockedRowNumbers = $executionSkippedRows
        ->merge($executionFailedRows)
        ->pluck('row_number')
        ->filter()
        ->map(fn ($rowNumber) => (int) $rowNumber)
        ->unique()
        ->values();
    $customerRowsForResult = $module === 'customers'
        ? $validRows->reject(fn ($row) => $runtimeBlockedRowNumbers->contains((int) ($row['row_number'] ?? 0)))
        : collect();
    $customerEntityCounts = $module === 'customers'
        ? $customerRowsForResult
            ->groupBy(fn ($row) => $customerEntityKey($row))
            ->map->count()
            ->filter()
        : collect();
    $hasCustomerEntityBreakdown = $customerEntityCounts->isNotEmpty();
    $customerEntityLabel = fn (array $row): array => $customerEntityMeta[$customerEntityKey($row)] ?? $customerEntityMeta['direct_customer'];
    $reasonLabel = function (?string $category): string {
        return match ($category) {
            'duplicate' => 'Duplicate',
            'lookup_failure' => 'Lookup Failure',
            'business_rule' => 'Business Rule',
            'already_imported' => 'Already Imported',
            'blank_row' => 'Blank Row',
            'unknown' => 'Failed',
            default => 'Validation',
        };
    };
    $requiredFields = collect($moduleConfig['fields'] ?? [])->filter(fn ($field) => !empty($field['required']))->pluck('label')->values()->all();
    $previewColumns = ['import_action', 'mapped_data'];
@endphp

<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ route('imports.mapping', $module) }}" class="ph-import-back">&larr; Back to Mapping</a>
            <h1>{{ $moduleConfig['label'] }} Import Preview</h1>
            <p>Review valid and invalid rows before executing the import.</p>
        </div>
        <div class="ph-import-actions">
            <form method="POST" action="{{ route('imports.reset', $module) }}">
                @csrf
                <button type="submit" class="ph-import-btn-secondary">Upload New File</button>
            </form>
            <a href="{{ route('imports.template', $module) }}" class="ph-import-btn-secondary">Download Template</a>
            @if($errorReportAvailable)
                <a href="{{ route('imports.error-report', $module) }}" class="ph-import-btn-secondary">Download Error Report</a>
            @endif
            <form method="POST" action="{{ route('imports.execute', $module) }}">
                @csrf
                <button type="submit" class="ph-import-btn-primary" @disabled($validRows->isEmpty() || $alreadyImported)>{{ $alreadyImported ? 'Import Completed' : 'Import Valid Rows' }}</button>
            </form>
        </div>
    </div>

    @if($lastResult)
        <div class="ph-import-result-banner">
            <strong>Import Completed Successfully.</strong>
            Processed {{ $lastResult['processed'] ?? 0 }}, created {{ $lastResult['created'] ?? 0 }}, updated {{ $lastResult['updated'] ?? 0 }}, skipped {{ $lastResult['skipped'] ?? 0 }}, failed {{ $lastResult['failed'] ?? 0 }}.
        </div>
    @elseif($alreadyImported)
        <div class="ph-import-result-banner">
            <strong>Import already completed.</strong>
            This preview has already been executed, so duplicate import is blocked.
        </div>
    @endif

    <div class="ph-import-preview-helper">
        {{ $alreadyImported ? 'This preview has already been imported. Duplicate import is blocked.' : 'Only valid rows will be imported. Invalid rows will be skipped.' }}
    </div>

    @if(!empty($preview['no_data_error']))
        <div class="ph-import-warning-banner">
            <strong>{{ $preview['no_data_error'] }}</strong>
            <span>Parsed headers were detected, but no importable data rows were read from the uploaded file.</span>
        </div>
    @endif

    @include('imports.partials.summary-cards', ['validCount' => $validRows->count(), 'invalidCount' => $invalidRows->count(), 'totalRows' => $preview['row_count'] ?? 0])

    <section class="ph-import-card">
        <div class="ph-import-panel-head">
            <div>
                <h2>Import Read Summary</h2>
                <p>Use this to verify the file was parsed before validation starts.</p>
            </div>
        </div>
        <div class="ph-import-stats">
            <div class="ph-import-stat">
                <span>Detected Sheet</span>
                <strong>{{ $preview['sheet_name'] ?? 'Unknown' }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Header Row</span>
                <strong>{{ $preview['header_row_number'] ?? 1 }}</strong>
            </div>
            <div class="ph-import-stat info">
                <span>Raw Rows Read</span>
                <strong>{{ $preview['raw_row_count'] ?? ($preview['row_count'] ?? 0) }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Non-empty Rows Read</span>
                <strong>{{ $preview['non_empty_row_count'] ?? ($preview['mapped_row_count'] ?? ($preview['row_count'] ?? 0)) }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Mapped Rows</span>
                <strong>{{ $preview['mapped_row_count'] ?? ($preview['row_count'] ?? 0) }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Ignored Blank Rows</span>
                <strong>{{ $preview['blank_row_count'] ?? 0 }}</strong>
            </div>
        </div>
    </section>
    @if(!empty($requiredFields))
        @include('imports.partials.required-banner', ['requiredFields' => $requiredFields])
    @endif

    @if($invalidRows->isNotEmpty() || $executionPreviewInvalidRows->isNotEmpty() || $executionSkippedRows->isNotEmpty() || $executionFailedRows->isNotEmpty() || !empty($preview['no_data_error']))
        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Parsed Headers</h2>
                    <p>Use these normalized headers to debug mapping issues when validation fails.</p>
                </div>
            </div>
            <div class="ph-import-reason-chip-row">
                @foreach(($preview['headers'] ?? []) as $header)
                    <span class="ph-import-reason-chip">{{ $header }}</span>
                @endforeach
            </div>
        </section>
    @endif

    @if($lastResult)
        <section class="ph-import-card ph-import-completion-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Import Summary</h2>
                    <p>Uploaded rows can become different PHOS records after import.</p>
                </div>
            </div>
            <div class="ph-import-completion-grid">
                <div class="ph-import-completion-metric">
                    <span>Uploaded Rows</span>
                    <strong>{{ $preview['row_count'] ?? 0 }}</strong>
                </div>
                @if($hasCustomerEntityBreakdown)
                    <div class="ph-import-completion-mix">
                        <span>Imported</span>
                        <div class="ph-import-entity-list">
                            @foreach($customerEntityMeta as $entityKey => $entity)
                                @if(($customerEntityCounts[$entityKey] ?? 0) > 0)
                                    <div class="ph-import-entity-row">
                                        <span class="ph-import-check" aria-hidden="true">OK</span>
                                        <span>{{ $entity['plural'] }}</span>
                                        <strong>{{ $customerEntityCounts[$entityKey] }}</strong>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="ph-import-completion-metric success">
                        <span>Imported</span>
                        <strong>{{ $lastResult['created'] ?? 0 }}</strong>
                    </div>
                @endif
                @if(($lastResult['updated'] ?? 0) > 0)
                    <div class="ph-import-completion-metric warning">
                        <span>Updated</span>
                        <strong>{{ $lastResult['updated'] }}</strong>
                    </div>
                @endif
                @if(($lastResult['skipped'] ?? 0) > 0)
                    <div class="ph-import-completion-metric danger">
                        <span>Skipped</span>
                        <strong>{{ $lastResult['skipped'] }}</strong>
                    </div>
                @endif
                @if(($lastResult['failed'] ?? 0) > 0)
                    <div class="ph-import-completion-metric danger">
                        <span>Failed</span>
                        <strong>{{ $lastResult['failed'] }}</strong>
                    </div>
                @endif
            </div>

            @if($hasCustomerEntityBreakdown)
                <div class="ph-import-result-actions">
                    @if(($customerEntityCounts['direct_customer'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('customers.index'))
                        <a href="{{ route('customers.index') }}" class="ph-import-btn-secondary">View Customers</a>
                    @endif
                    @if(($customerEntityCounts['business_partner'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('business-partners.index'))
                        <a href="{{ route('business-partners.index') }}" class="ph-import-btn-secondary">View Business Partners</a>
                    @endif
                    @if(($customerEntityCounts['actual_client'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has('business-partners.index'))
                        <a href="{{ route('business-partners.index') }}" class="ph-import-btn-secondary">View Actual Clients</a>
                    @endif
                    @if((($lastResult['skipped'] ?? 0) > 0 || ($lastResult['failed'] ?? 0) > 0) && $errorReportAvailable)
                        <a href="{{ route('imports.error-report', $module) }}" class="ph-import-btn-secondary">View Details</a>
                    @endif
                </div>
            @elseif((($lastResult['skipped'] ?? 0) > 0 || ($lastResult['failed'] ?? 0) > 0) && $errorReportAvailable)
                <div class="ph-import-result-actions">
                    <a href="{{ route('imports.error-report', $module) }}" class="ph-import-btn-secondary">View Details</a>
                </div>
            @endif
        </section>

        <div class="ph-import-stats">
            <div class="ph-import-stat info">
                <span>Processed</span>
                <strong>{{ $lastResult['processed'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat success">
                <span>Created</span>
                <strong>{{ $lastResult['created'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat warning">
                <span>Updated</span>
                <strong>{{ $lastResult['updated'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat danger">
                <span>Skipped</span>
                <strong>{{ $lastResult['skipped'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat danger">
                <span>Failed</span>
                <strong>{{ $lastResult['failed'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Duplicate Rows Skipped</span>
                <strong>{{ $lastResult['duplicate_rows_skipped'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Validation Errors</span>
                <strong>{{ $lastResult['validation_errors_count'] ?? 0 }}</strong>
            </div>
            <div class="ph-import-stat">
                <span>Ignored Blank Rows</span>
                <strong>{{ $lastResult['ignored_blank_rows_count'] ?? 0 }}</strong>
            </div>
        </div>

        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Execution Details</h2>
                    <p>Review which rows were blocked before import, skipped during execution, or failed unexpectedly.</p>
                </div>
            </div>

            @if($reasonGroups->isNotEmpty())
                <div class="ph-import-reason-chip-row">
                    @foreach($reasonGroups as $group)
                        <span class="ph-import-reason-chip">
                            {{ $reasonLabel($group['reason_category'] ?? null) }} - {{ $group['count'] }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="ph-import-result-details-grid">
                <details class="ph-import-result-details" {{ $executionPreviewInvalidRows->isNotEmpty() ? 'open' : '' }}>
                    <summary>Validation Blocked Rows ({{ $executionPreviewInvalidRows->count() }})</summary>
                    <div class="ph-import-result-items">
                        @forelse($executionPreviewInvalidRows as $row)
                            <article class="ph-import-result-item">
                                <div class="ph-import-result-item-head">
                                    <strong>Row #{{ $row['row_number'] }}</strong>
                                    <span class="ph-import-result-badge">{{ $reasonLabel($row['reason_category'] ?? null) }}</span>
                                </div>
                                <div class="ph-import-result-identifier">{{ $row['identifier'] ?: 'Row data' }}</div>
                                <p>{{ $row['reason'] ?? '' }}</p>
                                @if(!empty($row['error_details']))
                                    <ul>
                                        @foreach($row['error_details'] as $detail)
                                            <li><strong>{{ $detail['column'] ?? \Illuminate\Support\Str::of($detail['field'] ?? 'general')->replace('_', ' ')->title() }}:</strong> {{ $detail['reason'] ?? '' }} @if(!empty($detail['suggestion']))<span class="ph-import-suggestion">Suggested fix: {{ $detail['suggestion'] }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        @empty
                            <div class="ph-import-result-empty">No rows were blocked during preview validation.</div>
                        @endforelse
                    </div>
                </details>

                <details class="ph-import-result-details" {{ $executionSkippedRows->isNotEmpty() ? 'open' : '' }}>
                    <summary>Skipped Rows ({{ $executionSkippedRows->count() }})</summary>
                    <div class="ph-import-result-items">
                        @forelse($executionSkippedRows as $row)
                            <article class="ph-import-result-item">
                                <div class="ph-import-result-item-head">
                                    <strong>Row #{{ $row['row_number'] }}</strong>
                                    <span class="ph-import-result-badge">{{ $reasonLabel($row['reason_category'] ?? null) }}</span>
                                </div>
                                <div class="ph-import-result-identifier">{{ $row['identifier'] ?: 'Row data' }}</div>
                                <p>{{ $row['reason'] ?? '' }}</p>
                                @if(!empty($row['error_details']))
                                    <ul>
                                        @foreach($row['error_details'] as $detail)
                                            <li><strong>{{ $detail['column'] ?? \Illuminate\Support\Str::of($detail['field'] ?? 'general')->replace('_', ' ')->title() }}:</strong> {{ $detail['reason'] ?? '' }} @if(!empty($detail['suggestion']))<span class="ph-import-suggestion">Suggested fix: {{ $detail['suggestion'] }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        @empty
                            <div class="ph-import-result-empty">No rows were skipped during execution.</div>
                        @endforelse
                    </div>
                </details>

                <details class="ph-import-result-details" {{ $executionFailedRows->isNotEmpty() ? 'open' : '' }}>
                    <summary>Failed Rows ({{ $executionFailedRows->count() }})</summary>
                    <div class="ph-import-result-items">
                        @forelse($executionFailedRows as $row)
                            <article class="ph-import-result-item ph-import-result-item-failed">
                                <div class="ph-import-result-item-head">
                                    <strong>Row #{{ $row['row_number'] }}</strong>
                                    <span class="ph-import-result-badge is-danger">{{ $reasonLabel($row['reason_category'] ?? null) }}</span>
                                </div>
                                <div class="ph-import-result-identifier">{{ $row['identifier'] ?: 'Row data' }}</div>
                                <p>{{ $row['reason'] ?? '' }}</p>
                                @if(!empty($row['error_details']))
                                    <ul>
                                        @foreach($row['error_details'] as $detail)
                                            <li><strong>{{ $detail['column'] ?? \Illuminate\Support\Str::of($detail['field'] ?? 'general')->replace('_', ' ')->title() }}:</strong> {{ $detail['reason'] ?? '' }} @if(!empty($detail['suggestion']))<span class="ph-import-suggestion">Suggested fix: {{ $detail['suggestion'] }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        @empty
                            <div class="ph-import-result-empty">No unexpected failures occurred during execution.</div>
                        @endforelse
                    </div>
                </details>
            </div>
        </section>
    @endif

    @if($module === 'assets' && !empty($assetPrecheck))
        <div class="ph-import-warning-banner">
            <strong>{{ count($assetPrecheck) }} {{ count($assetPrecheck) === 1 ? 'product needs' : 'products need' }} stock mode update before import.</strong>
            <span>These products are currently untracked, so asset import cannot continue until they are switched to a tracked mode.</span>
        </div>
    @endif

    <div class="ph-import-layout">
        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Imported Rows Preview</h2>
                    <p>Showing up to the first 20 rows that are currently ready to import.</p>
                </div>
            </div>

            <div class="ph-import-table-wrap responsive-table-shell">
                <div class="responsive-table-scroll">
                    <table class="ph-import-table">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Action</th>
                                <th>Mapped Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($validRows->take(20) as $row)
                                @php($entity = $customerEntityLabel($row))
                                <tr>
                                    <td>#{{ $row['row_number'] }}</td>
                                    <td>
                                        @if($module === 'customers')
                                            <span class="ph-import-entity-chip {{ $entity['class'] }}">{{ $entity['label'] }}</span>
                                        @else
                                            {{ $row['mapped']['import_action'] ?? ($row['payload']['import_action'] ?? 'Create') }}
                                        @endif
                                    </td>
                                    <td><pre>{{ json_encode($row['mapped'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3">No valid rows available.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="ph-import-mobile-list">
                @forelse($validRows->take(20) as $row)
                    @php($entity = $customerEntityLabel($row))
                    <article class="ph-import-mobile-card">
                        <div class="ph-import-mobile-row">Row #{{ $row['row_number'] }}</div>
                        <div class="ph-import-mobile-meta">
                            <span>Action</span>
                            <strong>
                                @if($module === 'customers')
                                    <span class="ph-import-entity-chip {{ $entity['class'] }}">{{ $entity['label'] }}</span>
                                @else
                                    {{ $row['mapped']['import_action'] ?? ($row['payload']['import_action'] ?? 'Create') }}
                                @endif
                            </strong>
                        </div>
                        <div class="ph-import-mobile-meta">
                            <span>Mapped Data</span>
                            <strong>{{ json_encode($row['mapped'] ?? [], JSON_UNESCAPED_UNICODE) }}</strong>
                        </div>
                    </article>
                @empty
                    <div class="ph-import-empty">No valid rows available.</div>
                @endforelse
            </div>
        </section>

        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Skipped Rows</h2>
                    <p>Each skipped row shows the row number, field, and exact reason.</p>
                </div>
            </div>

            <div class="ph-import-invalid-list">
                @forelse($invalidRows->take(20) as $row)
                    <article class="ph-import-invalid-card">
                        <strong>Row #{{ $row['row_number'] }}</strong>
                        <ul>
                            @foreach(($row['error_details'] ?? []) as $detail)
                                <li><strong>{{ $detail['column'] ?? \Illuminate\Support\Str::of($detail['field'] ?? 'general')->replace('_', ' ')->title() }}:</strong> {{ $detail['reason'] ?? '' }} @if(!empty($detail['suggestion']))<span class="ph-import-suggestion">Suggested fix: {{ $detail['suggestion'] }}</span>@endif</li>
                            @endforeach
                        </ul>
                        @if(($row['guidance']['type'] ?? null) === 'untracked_product')
                            <div class="ph-import-guidance-card">
                                <div class="ph-import-guidance-copy">
                                    <div><strong>Product:</strong> {{ $row['guidance']['product_name'] }}</div>
                                    <div><strong>Current Mode:</strong> {{ $row['guidance']['current_mode_label'] }}</div>
                                    <p>{{ $row['guidance']['message'] }}</p>
                                    <div class="ph-import-guidance-tags">
                                        @foreach(($row['guidance']['suggested_modes'] ?? []) as $mode)
                                            <span>{{ $mode }}</span>
                                        @endforeach
                                    </div>
                                </div>
                                @if(\Illuminate\Support\Facades\Route::has('products.index'))
                                    <a href="{{ route('products.index') }}" class="ph-import-guidance-btn">Open Product Master</a>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="ph-import-clean-state">No validation errors. This file is ready to import.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>

@include('imports.partials.shared-styles')
@endsection
