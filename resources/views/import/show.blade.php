@extends('layouts.app')

@section('content')
<div class="import-shell">
    <div class="import-head">
        <div>
            <a href="{{ route('imports.index') }}" class="import-back">← Back to Imports</a>
            <h1>{{ $moduleConfig['label'] }}</h1>
            <p>{{ $moduleConfig['description'] }}</p>
        </div>
        <a href="{{ route('imports.template', $module) }}" class="import-secondary-btn">Download Template</a>
    </div>

    <div class="import-step-grid">
        <section class="import-panel">
            <h2>1. Upload File</h2>
            <p>Supported formats: <strong>CSV</strong> and <strong>XLSX</strong>. The importer reads the first worksheet for Excel files.</p>
            <form method="POST" action="{{ route('imports.upload', $module) }}" enctype="multipart/form-data" class="import-upload-form">
                @csrf
                <input type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="ops-input">
                @error('import_file')
                    <div class="import-error">{{ $message }}</div>
                @enderror
                <button type="submit" class="import-primary-btn">Upload & Continue</button>
            </form>
        </section>

        <section class="import-panel">
            <h2>Import Flow</h2>
            <ol class="import-flow">
                <li>Upload CSV or Excel file</li>
                <li>Map spreadsheet columns to Prime Healers OS fields</li>
                <li>Review valid and invalid rows</li>
                <li>Import valid rows in chunks</li>
            </ol>
            @if($upload)
                <div class="import-meta">
                    <strong>Latest upload:</strong> {{ $upload['original_name'] ?? 'file' }}<br>
                    <strong>Rows:</strong> {{ $upload['row_count'] ?? 0 }}
                </div>
                <div class="import-flow-actions">
                    <a href="{{ route('imports.mapping', $module) }}" class="import-secondary-btn">Open Mapping</a>
                    @if($preview)
                        <a href="{{ route('imports.preview', $module) }}" class="import-secondary-btn">Open Preview</a>
                    @endif
                    <form method="POST" action="{{ route('imports.reset', $module) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="import-secondary-btn">Upload New File</button>
                    </form>
                </div>
            @endif
        </section>
    </div>
</div>

<style>
    .import-shell{max-width:1180px;margin:0 auto;display:grid;gap:20px}
    .import-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:700}
    .import-head h1{margin:10px 0 6px;font-size:32px;letter-spacing:-.04em}
    .import-head p{margin:0;color:#64748b;max-width:760px;line-height:1.6}
    .import-step-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.9fr);gap:18px}
    .import-panel{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:24px;display:grid;gap:14px}
    .import-panel h2{margin:0;font-size:22px}
    .import-upload-form{display:grid;gap:12px}
    .import-flow{margin:0;padding-left:18px;color:#475569;line-height:1.7}
    .import-meta{padding:14px;border-radius:16px;background:#f8fafc;color:#334155;line-height:1.6}
    .import-flow-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .import-error{font-size:13px;color:#b91c1c;font-weight:700}
    .import-primary-btn,
    .import-secondary-btn{
        display:inline-flex;align-items:center;justify-content:center;gap:8px;
        min-height:42px;padding:0 16px;border-radius:12px;font-size:13px;font-weight:800;
        letter-spacing:.01em;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,filter .15s ease,border-color .15s ease
    }
    .import-primary-btn{
        border:1px solid #16a34a;background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);
        color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.22)
    }
    .import-primary-btn:hover{transform:translateY(-1px);box-shadow:0 16px 28px rgba(22,163,74,.28);filter:saturate(1.05)}
    .import-secondary-btn{
        border:1px solid #cbd5e1;background:#fff;color:#0f172a;box-shadow:0 8px 18px rgba(15,23,42,.06)
    }
    .import-secondary-btn:hover{transform:translateY(-1px);border-color:#94a3b8;color:#0f172a;box-shadow:0 12px 22px rgba(15,23,42,.08)}
    .import-primary-btn:focus-visible,
    .import-secondary-btn:focus-visible{outline:3px solid rgba(37,99,235,.18);outline-offset:2px}
    .import-upload-form .import-primary-btn{justify-self:start;min-width:190px}
    @media (max-width:880px){.import-step-grid{grid-template-columns:1fr}}
    @media (max-width:640px){.import-flow-actions{display:grid}.import-flow-actions form{width:100%}.import-flow-actions .import-secondary-btn{width:100%}}
</style>
@endsection
