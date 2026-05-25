@extends('layouts.app')

@section('content')
<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ route('imports.index') }}" class="ph-import-back">&larr; Back to Imports</a>
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
                <h2>Upload {{ $moduleConfig['label'] }} File</h2>
                <p>Accepted formats: <strong>CSV</strong> and <strong>XLSX</strong>. The importer reads the first worksheet for Excel files.</p>
            </div>

            <form method="POST" action="{{ route('imports.upload', $module) }}" enctype="multipart/form-data" class="ph-import-upload-form">
                @csrf
                <label for="import_file_{{ $module }}" class="ph-import-dropzone">
                    <span class="ph-import-dropzone-icon" aria-hidden="true">+</span>
                    <span class="ph-import-dropzone-title">Choose {{ \Illuminate\Support\Str::singular($moduleConfig['label']) }} file</span>
                    <span class="ph-import-dropzone-copy">Click to browse your CSV or XLSX file, then continue to column mapping and validation preview.</span>
                    <input id="import_file_{{ $module }}" type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="ph-import-file-input">
                </label>
                <div class="ph-import-file-note" id="import-file-note-{{ $module }}">No file selected yet.</div>
                @error('import_file')
                    <div class="ph-import-error">{{ $message }}</div>
                @enderror

                <div class="ph-import-helper-banner">
                    <strong>Upload only</strong>
                    <span>The file will be stored for mapping and preview first. No data is inserted at this step.</span>
                </div>

                <button type="submit" class="ph-import-btn-primary">Upload &amp; Continue</button>
            </form>
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
                        <strong>Rows:</strong> {{ $upload['row_count'] ?? 0 }}
                    </div>
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

@include('imports.partials.shared-styles')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('import_file_{{ $module }}');
    const fileName = document.getElementById('import-file-note-{{ $module }}');

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
