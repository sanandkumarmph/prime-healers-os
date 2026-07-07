@extends('layouts.app')

@section('breadcrumbs')
    <span class="import-breadcrumb-suppressed" aria-hidden="true" style="display:none!important"></span>
@endsection

@section('content')
<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ route('imports.index') }}" class="ph-import-back">&larr; Back to Data Import</a>
            <h1>Opening Balance Upload &amp; Preview</h1>
            <p>Upload a CSV or XLSX file to preview opening balance rows before import. No data will be inserted yet.</p>
        </div>
        <div class="ph-import-actions">
            <a href="{{ route('imports.template', 'opening-balances') }}" class="ph-import-btn-secondary">Download Template</a>
        </div>
    </div>

    <div class="ph-import-upload-grid">
        <section class="ph-import-card">
            <div class="ph-import-section-copy">
                <span class="ph-import-kicker">Step 1</span>
                <h2>Upload Opening Balance File</h2>
                <p><strong>CSV</strong> or <strong>XLSX</strong>. First worksheet is used.</p>
            </div>

            <form method="POST" action="{{ route('imports.opening-balances.preview') }}" enctype="multipart/form-data" class="ph-import-upload-form">
                @csrf
                <label for="opening_balance_import_file" class="ph-import-dropzone">
                    <span class="ph-import-dropzone-icon" aria-hidden="true">UP</span>
                    <span class="ph-import-dropzone-title">Drag CSV/XLSX here</span>
                    <span class="ph-import-dropzone-copy">or <span class="ph-import-browse-text">Browse Files</span></span>
                    <span class="ph-import-dropzone-format">CSV | XLSX | Max 10MB</span>
                    <input id="opening_balance_import_file" type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="ph-import-file-input">
                </label>
                <div class="ph-import-file-note ph-import-selected-file" id="opening-balance-import-file-name" hidden>
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
                    <strong>Preview only</strong>
                    <span>No data will be inserted. Review valid and invalid rows before import is enabled.</span>
                </div>

                <button type="submit" class="ph-import-btn-primary">Upload &amp; Preview</button>
            </form>
        </section>

        <aside class="ph-import-side-panel">
            @component('imports.partials.upload-info-card', ['kicker' => 'Required', 'title' => 'Required Fields'])
                <div class="ph-import-field-chips">
                    <span>Customer Phone</span>
                    <span>Opening Balance</span>
                </div>
            @endcomponent

            @component('imports.partials.upload-info-card', ['kicker' => 'What happens next', 'title' => 'Upload Flow'])
                <ol class="ph-import-flow">
                    <li>Upload your opening balance CSV or XLSX file</li>
                    <li>Review the first 20 rows in preview</li>
                    <li>Check valid and invalid rows separately</li>
                    <li>Fix the source sheet and re-upload if needed</li>
                </ol>
            @endcomponent
        </aside>
    </div>
</div>

@include('imports.partials.shared-styles')

@endsection
