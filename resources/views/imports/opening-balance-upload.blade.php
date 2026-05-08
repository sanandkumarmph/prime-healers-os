@extends('layouts.app')

@section('content')
<div class="import-upload-shell">
    <div class="import-upload-head">
        <div>
            <a href="{{ route('imports.index') }}" class="import-back">← Back to Data Import</a>
            <h1>Opening Balance Upload & Preview</h1>
            <p>Upload a CSV or XLSX file to preview opening balance rows. No data will be inserted yet.</p>
        </div>
    </div>

    <div class="import-upload-card">
        <form method="POST" action="{{ route('imports.opening-balances.preview') }}" enctype="multipart/form-data" class="import-upload-form">
            @csrf
            <label class="import-upload-label" for="opening_balance_import_file">Opening Balance CSV / XLSX File</label>
            <input id="opening_balance_import_file" type="file" name="import_file" accept=".csv,.txt,.xlsx" class="ops-input" required>
            @error('import_file')
                <div class="import-error">{{ $message }}</div>
            @enderror
            <div class="import-required-list">
                <strong>Required fields</strong>
                <span>Customer Phone, Opening Balance</span>
            </div>
            <button type="submit" class="ops-btn">Upload & Preview</button>
        </form>
    </div>
</div>

<style>
    .import-upload-shell{max-width:860px;margin:0 auto;display:grid;gap:18px}
    .import-upload-head h1{margin:10px 0 6px;font-size:32px;letter-spacing:-.04em}
    .import-upload-head p{margin:0;color:#64748b;line-height:1.6}
    .import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:700}
    .import-upload-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:24px}
    .import-upload-form{display:grid;gap:14px}
    .import-upload-label{font-size:13px;font-weight:800;color:#0f172a}
    .import-required-list{padding:14px 16px;border-radius:16px;background:#f8fafc;color:#475569;display:grid;gap:6px}
    .import-required-list strong{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#1d4ed8}
    .import-error{font-size:13px;font-weight:700;color:#b91c1c}
</style>
@endsection
