@extends('layouts.app')

@section('breadcrumbs')
    <span class="import-breadcrumb-suppressed" aria-hidden="true" style="display:none!important"></span>
@endsection

@section('content')
@php
    $setupItem = $setupItem ?? ['status' => 'ready', 'missing' => [], 'download_note' => null, 'status_label' => 'Ready to Import'];
    $isBlockedImport = ($setupItem['status'] ?? 'ready') === 'blocked';
    $missingDependencies = collect($setupItem['missing'] ?? []);
    $latestResult = $preview['last_result'] ?? null;
    $latestValidRows = collect($preview['valid_rows'] ?? []);
    $latestSkippedRows = collect($latestResult['skipped_rows'] ?? []);
    $latestFailedRows = collect($latestResult['failed_rows'] ?? []);
    $latestBlockedRows = $latestSkippedRows
        ->merge($latestFailedRows)
        ->pluck('row_number')
        ->filter()
        ->map(fn ($rowNumber) => (int) $rowNumber)
        ->unique();
    $latestCustomerEntityMeta = [
        'direct_customer' => 'Direct Customers',
        'business_partner' => 'Business Partners',
        'actual_client' => 'Actual Clients',
    ];
    $latestCustomerEntityCounts = ($module === 'customers' && $latestResult)
        ? $latestValidRows
            ->reject(fn ($row) => $latestBlockedRows->contains((int) ($row['row_number'] ?? 0)))
            ->groupBy(fn ($row) => (string) data_get($row, 'payload.import_entity', data_get($row, 'payload.customer_type', 'direct_customer')))
            ->map->count()
            ->filter()
        : collect();
@endphp

<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ route('imports.index') }}" class="ph-import-back">&larr; Back to Import Setup</a>
            <h1>{{ $moduleConfig['label'] }}</h1>
            <p>{{ $moduleConfig['description'] }}</p>
        </div>
        <div class="ph-import-actions">
            <a href="{{ route('imports.template', $module) }}" class="ph-import-btn-secondary">Download Template</a>
        </div>
    </div>

    <div class="ph-import-upload-grid">
        <section class="ph-import-card">
            <div class="ph-import-section-copy">
                <span class="ph-import-kicker">Step 1</span>
                <h2>{{ $isBlockedImport ? 'Import Not Ready Yet' : 'Upload File' }}</h2>
                <p>{{ $isBlockedImport ? 'Complete the prerequisites below before uploading this template.' : 'CSV or XLSX. First worksheet is used.' }}</p>
            </div>

            <div class="ph-import-prereq-card {{ $isBlockedImport ? 'is-blocked' : 'is-ready' }}">
                <div>
                    <span class="ph-import-prereq-status">{{ $isBlockedImport ? 'Blocked' : 'Ready' }}</span>
                    <strong>{{ $isBlockedImport ? 'This import is not ready yet.' : 'Prerequisites complete.' }}</strong>
                    <p>{{ $isBlockedImport ? ($setupItem['download_note'] ?? 'You can download the template now, but import requires completing prerequisites first.') : 'You can upload and validate this import now.' }}</p>
                </div>
                @if($missingDependencies->isNotEmpty())
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
                @endif
            </div>

            @if($isBlockedImport)
                <div class="ph-import-blocked-actions">
                    <a href="{{ route('imports.index') }}" class="ph-import-btn-secondary">Open Import Setup</a>
                    <a href="{{ route('imports.template', $module) }}" class="ph-import-btn-primary">Download Template</a>
                </div>
            @else
                <form method="POST" action="{{ route('imports.upload', $module) }}" enctype="multipart/form-data" class="ph-import-upload-form">
                    @csrf
                    <label for="import_file_{{ $module }}" class="ph-import-dropzone">
                        <span class="ph-import-dropzone-icon" aria-hidden="true">UP</span>
                        <span class="ph-import-dropzone-title">Drag CSV/XLSX here</span>
                        <span class="ph-import-dropzone-copy">or <span class="ph-import-browse-text">Browse Files</span></span>
                        <span class="ph-import-dropzone-format">CSV | XLSX | Max 10MB</span>
                        <input id="import_file_{{ $module }}" type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="ph-import-file-input">
                    </label>
                    <div class="ph-import-file-note ph-import-selected-file" id="import-file-note-{{ $module }}" hidden>
                        <span class="ph-import-selected-icon" aria-hidden="true">OK</span>
                        <span class="ph-import-selected-copy">
                            <strong data-file-name>No file selected</strong>
                            <small data-file-meta>Ready for upload</small>
                        </span>
                        <button type="button" class="ph-import-file-action" data-file-replace>Replace</button>
                        <button type="button" class="ph-import-file-action is-danger" data-file-remove>Remove</button>
                    </div>
                    @error('import_file')
                        <div class="ph-import-error">{{ $message }}</div>
                    @enderror

                    <div class="ph-import-helper-banner">
                        <strong>Upload only</strong>
                        <span>The file will be stored for mapping and preview first. No data is inserted at this step.</span>
                    </div>

                    <button type="submit" class="ph-import-btn-primary">Upload &amp; Continue</button>
                </form>
            @endif
        </section>

        <aside class="ph-import-side-panel">
            @component('imports.partials.upload-info-card', ['kicker' => 'Required', 'title' => 'Required Fields'])
                <div class="ph-import-field-chips">
                    @foreach(collect($moduleConfig['fields'] ?? [])->filter(fn ($field) => !empty($field['required']))->take(8) as $field)
                        <span>{{ $field['label'] }}</span>
                    @endforeach
                </div>
            @endcomponent

            @component('imports.partials.upload-info-card', ['kicker' => 'What happens next', 'title' => 'Upload Flow'])
                <ol class="ph-import-flow">
                    <li>Upload your CSV or XLSX file</li>
                    <li>Map spreadsheet columns to Prime Healers OS fields</li>
                    <li>Review valid and invalid rows</li>
                    <li>Import valid rows when the preview looks correct</li>
                </ol>
            @endcomponent

            @if($upload)
                @component('imports.partials.upload-info-card', ['kicker' => 'Current progress', 'title' => 'Latest Upload'])
                    <div class="ph-import-meta">
                        <strong>File:</strong> {{ $upload['original_name'] ?? 'Uploaded file' }}<br>
                        <strong>Uploaded Rows:</strong> {{ $upload['row_count'] ?? 0 }}
                    </div>
                    @if($latestResult)
                        <div class="ph-import-meta ph-import-last-result">
                            <strong>Last Import Result</strong>
                            @if($latestCustomerEntityCounts->isNotEmpty())
                                <div class="ph-import-last-result-list">
                                    @foreach($latestCustomerEntityMeta as $entityKey => $entityLabel)
                                        @if(($latestCustomerEntityCounts[$entityKey] ?? 0) > 0)
                                            <span>{{ $entityLabel }} <b>{{ $latestCustomerEntityCounts[$entityKey] }}</b></span>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <div class="ph-import-last-result-list">
                                    @if(($latestResult['created'] ?? 0) > 0)<span>Imported <b>{{ $latestResult['created'] }}</b></span>@endif
                                    @if(($latestResult['updated'] ?? 0) > 0)<span>Updated <b>{{ $latestResult['updated'] }}</b></span>@endif
                                </div>
                            @endif
                            <div class="ph-import-last-result-foot">
                                @if(($latestResult['skipped'] ?? 0) > 0)<span>Skipped {{ $latestResult['skipped'] }}</span>@endif
                                @if(($latestResult['failed'] ?? 0) > 0)<span>Failed {{ $latestResult['failed'] }}</span>@endif
                            </div>
                        </div>
                    @endif
                    <div class="ph-import-actions">
                        <a href="{{ route('imports.mapping', $module) }}" class="ph-import-btn-secondary">Open Mapping</a>
                        @if($preview)
                            <a href="{{ route('imports.preview', $module) }}" class="ph-import-btn-secondary">Open Preview</a>
                        @endif
                        <form method="POST" action="{{ route('imports.reset', $module) }}">
                            @csrf
                            <button type="submit" class="ph-import-btn-secondary">Upload New File</button>
                        </form>
                    </div>
                @endcomponent
            @endif
        </aside>
    </div>
</div>

<style>
.ph-import-prereq-card{display:grid;gap:10px;margin:0 0 14px;padding:12px 14px;border:1px solid #dbe4f0;border-radius:16px;background:#f8fafc}.ph-import-prereq-card.is-ready{border-color:#bbf7d0;background:#f0fdf4}.ph-import-prereq-card.is-blocked{border-color:#fed7aa;background:#fff7ed}.ph-import-prereq-card strong{display:block;margin-top:4px;font-size:16px;color:#0f172a}.ph-import-prereq-card p{margin:4px 0 0;color:#64748b;font-size:13px}.ph-import-prereq-status{display:inline-flex;width:max-content;height:24px;align-items:center;border-radius:999px;background:#fff;color:#475569;padding:0 9px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}.ph-import-prereq-card ul{margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px;list-style:none}.ph-import-prereq-card li a,.ph-import-prereq-card li span{display:inline-flex;align-items:center;height:30px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;padding:0 10px;color:#4338ca;font-size:12px;font-weight:900;text-decoration:none}.ph-import-blocked-actions{display:flex;gap:10px;flex-wrap:wrap}.ph-import-blocked-actions .ph-import-btn-primary,.ph-import-blocked-actions .ph-import-btn-secondary{min-height:40px}.ph-import-last-result{display:grid;gap:8px}.ph-import-last-result>strong{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#475569}.ph-import-last-result-list{display:grid;gap:6px}.ph-import-last-result-list span,.ph-import-last-result-foot span{display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:13px;color:#475569}.ph-import-last-result-list b{font-size:14px;color:#0f172a}.ph-import-last-result-foot{display:flex;gap:8px;flex-wrap:wrap}.ph-import-last-result-foot span{display:inline-flex;min-height:24px;padding:0 8px;border-radius:999px;background:#fff7ed;color:#9a3412;font-weight:800}@media(max-width:760px){.ph-import-prereq-card{padding:10px}.ph-import-blocked-actions{display:grid}.ph-import-blocked-actions .ph-import-btn-primary,.ph-import-blocked-actions .ph-import-btn-secondary{width:100%}}
</style>
@include('imports.partials.shared-styles')

@endsection
