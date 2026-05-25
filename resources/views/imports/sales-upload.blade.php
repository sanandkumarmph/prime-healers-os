@extends('layouts.app')

@section('content')
<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ route('imports.index') }}" class="ph-import-back">&larr; Back to Data Import</a>
            <h1>Sales Upload &amp; Preview</h1>
            <p>Upload a CSV or XLSX file to validate sales rows before import. No data will be inserted. You can review valid and invalid rows first.</p>
        </div>
        <div class="ph-import-actions">
            <a href="{{ route('imports.template', 'sales') }}" class="ph-import-btn-secondary">Download Template</a>
        </div>
    </div>

    <div class="ph-import-upload-grid">
        <section class="ph-import-card">
            <div class="ph-import-section-copy">
                <span class="ph-import-kicker">Step 1</span>
                <h2>Upload Sales File</h2>
                <p>Accepted formats: <strong>CSV</strong> and <strong>XLSX</strong>. The preview reads the first worksheet for Excel files.</p>
            </div>

            <form method="POST" action="{{ route('imports.sales.preview') }}" enctype="multipart/form-data" class="ph-import-upload-form">
                @csrf
                <label for="sales_import_file" class="ph-import-dropzone">
                    <span class="ph-import-dropzone-icon" aria-hidden="true">+</span>
                    <span class="ph-import-dropzone-title">Choose sales file</span>
                    <span class="ph-import-dropzone-copy">Drag and drop is optional. Click to browse your CSV or XLSX file.</span>
                    <input id="sales_import_file" type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="ph-import-file-input">
                </label>
                <div class="ph-import-file-note" id="sales-import-file-name">No file selected yet.</div>
                @error('import_file')
                    <div class="ph-import-error">{{ $message }}</div>
                @enderror

                <div class="ph-import-helper-banner">
                    <strong>Preview only</strong>
                    <span>No data will be inserted. You can review valid and invalid rows first.</span>
                </div>

                <button type="submit" class="ph-import-btn-primary">Upload &amp; Preview</button>
            </form>
        </section>

        <aside class="ph-import-side-panel">
            @component('imports.partials.upload-info-card', ['kicker' => 'Required', 'title' => 'Required Fields'])
                <div class="ph-import-field-chips">
                    <span>Customer Phone</span>
                    <span>Product Name</span>
                    <span>Quantity</span>
                    <span>Sale Amount</span>
                    <span>Sale Date</span>
                </div>
            @endcomponent

            @component('imports.partials.upload-info-card', ['kicker' => 'What happens next', 'title' => 'Upload Flow'])
                <ol class="ph-import-flow">
                    <li>Upload your sales CSV or XLSX file</li>
                    <li>Review the first 20 rows in preview</li>
                    <li>Check valid and invalid rows separately</li>
                    <li>Fix the source sheet and re-upload if needed</li>
                </ol>
            @endcomponent
        </aside>
    </div>
</div>

@include('imports.partials.shared-styles')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('sales_import_file');
    const fileName = document.getElementById('sales-import-file-name');

    if (!input || !fileName) {
        return;
    }

    input.addEventListener('change', function () {
        const selected = input.files && input.files[0] ? input.files[0].name : 'No file selected yet.';
        fileName.textContent = selected;
    });
});
</script>
@endsection
