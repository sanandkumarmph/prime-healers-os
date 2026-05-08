@extends('layouts.app')

@section('content')
<div class="sales-import-upload-page">
    <div class="sales-import-upload-head">
        <div>
            <a href="{{ route('imports.index') }}" class="sales-import-back">&larr; Back to Data Import</a>
            <h1>Sales Upload &amp; Preview</h1>
            <p>Upload a CSV or XLSX file to validate sales rows before import. No data will be inserted. You can review valid and invalid rows first.</p>
        </div>
        <a href="{{ route('imports.template', 'sales') }}" class="sales-import-secondary-btn">Download Template</a>
    </div>

    <div class="sales-import-upload-grid">
        <section class="sales-import-upload-card">
            <div class="sales-import-section-copy">
                <span class="sales-import-kicker">Step 1</span>
                <h2>Upload Sales File</h2>
                <p>Accepted formats: <strong>CSV</strong> and <strong>XLSX</strong>. The preview reads the first worksheet for Excel files.</p>
            </div>

            <form method="POST" action="{{ route('imports.sales.preview') }}" enctype="multipart/form-data" class="sales-import-upload-form">
                @csrf
                <label for="sales_import_file" class="sales-import-dropzone">
                    <span class="sales-import-dropzone-icon" aria-hidden="true">+</span>
                    <span class="sales-import-dropzone-title">Choose sales file</span>
                    <span class="sales-import-dropzone-copy">Drag and drop is optional. Click to browse your CSV or XLSX file.</span>
                    <input id="sales_import_file" type="file" name="import_file" accept=".csv,.txt,.xlsx" required class="sales-import-file-input">
                </label>
                <div class="sales-import-file-note" id="sales-import-file-name">No file selected yet.</div>
                @error('import_file')
                    <div class="sales-import-error">{{ $message }}</div>
                @enderror

                <div class="sales-import-helper-banner">
                    <strong>Preview only</strong>
                    <span>No data will be inserted. You can review valid and invalid rows first.</span>
                </div>

                <button type="submit" class="sales-import-primary-btn">Upload &amp; Preview</button>
            </form>
        </section>

        <aside class="sales-import-side-panel">
            <div class="sales-import-side-card">
                <span class="sales-import-kicker">Required</span>
                <h2>Required Fields</h2>
                <div class="sales-import-field-chips">
                    <span>Customer Phone</span>
                    <span>Product Name</span>
                    <span>Quantity</span>
                    <span>Sale Amount</span>
                    <span>Sale Date</span>
                </div>
            </div>

            <div class="sales-import-side-card">
                <span class="sales-import-kicker">What happens next</span>
                <h2>Upload Flow</h2>
                <ol class="sales-import-flow">
                    <li>Upload your sales CSV or XLSX file</li>
                    <li>Review the first 20 rows in preview</li>
                    <li>Check valid and invalid rows separately</li>
                    <li>Fix the source sheet and re-upload if needed</li>
                </ol>
            </div>
        </aside>
    </div>
</div>

<style>
    .sales-import-upload-page{max-width:1180px;margin:0 auto;display:grid;gap:20px}
    .sales-import-upload-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .sales-import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:800}
    .sales-import-upload-head h1{margin:10px 0 8px;font-size:34px;letter-spacing:-.04em;color:#0f172a}
    .sales-import-upload-head p{margin:0;max-width:760px;color:#64748b;line-height:1.65}
    .sales-import-upload-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(300px,.85fr);gap:18px}
    .sales-import-upload-card,
    .sales-import-side-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:24px;box-shadow:0 12px 28px rgba(15,23,42,.04)}
    .sales-import-side-panel{display:grid;gap:18px}
    .sales-import-section-copy{display:grid;gap:8px;margin-bottom:18px}
    .sales-import-kicker{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#2563eb}
    .sales-import-upload-card h2,
    .sales-import-side-card h2{margin:0;font-size:24px;color:#0f172a}
    .sales-import-upload-card p,
    .sales-import-side-card p{margin:0;color:#64748b;line-height:1.6}
    .sales-import-upload-form{display:grid;gap:14px}
    .sales-import-dropzone{display:grid;gap:8px;justify-items:start;padding:24px;border:1.5px dashed #93c5fd;border-radius:22px;background:linear-gradient(180deg,#f8fbff 0%,#eff6ff 100%);cursor:pointer}
    .sales-import-dropzone:hover{border-color:#60a5fa;background:linear-gradient(180deg,#f0f7ff 0%,#e0efff 100%)}
    .sales-import-dropzone-icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:14px;background:#dbeafe;color:#1d4ed8;font-size:28px;font-weight:500}
    .sales-import-dropzone-title{font-size:18px;font-weight:800;color:#0f172a}
    .sales-import-dropzone-copy{font-size:14px;line-height:1.55;color:#64748b}
    .sales-import-file-input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}
    .sales-import-file-note{font-size:13px;font-weight:700;color:#475569}
    .sales-import-helper-banner{display:grid;gap:4px;padding:14px 16px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
    .sales-import-helper-banner strong{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0f766e}
    .sales-import-primary-btn,
    .sales-import-secondary-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border-radius:14px;font-size:14px;font-weight:800;letter-spacing:.01em;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,filter .15s ease,border-color .15s ease}
    .sales-import-primary-btn{border:1px solid #16a34a;background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.2);justify-self:start}
    .sales-import-primary-btn:hover{transform:translateY(-1px);box-shadow:0 16px 28px rgba(22,163,74,.26);filter:saturate(1.05)}
    .sales-import-secondary-btn{border:1px solid #cbd5e1;background:#fff;color:#0f172a;box-shadow:0 8px 18px rgba(15,23,42,.06)}
    .sales-import-secondary-btn:hover{transform:translateY(-1px);border-color:#94a3b8}
    .sales-import-field-chips{display:flex;flex-wrap:wrap;gap:8px}
    .sales-import-field-chips span{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800}
    .sales-import-flow{margin:0;padding-left:18px;color:#475569;line-height:1.7}
    .sales-import-error{font-size:13px;font-weight:800;color:#b91c1c}
    @media (max-width:960px){.sales-import-upload-grid{grid-template-columns:1fr}}
    @media (max-width:640px){
        .sales-import-upload-head h1{font-size:28px}
        .sales-import-upload-card,
        .sales-import-side-card{padding:18px}
        .sales-import-primary-btn,
        .sales-import-secondary-btn{width:100%}
        .sales-import-primary-btn{justify-self:stretch}
    }
</style>

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
