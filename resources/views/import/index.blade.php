@extends('layouts.app')

@section('content')
<div class="import-shell">
    <div class="import-hero">
        <div>
            <div class="import-eyebrow">Data Import</div>
            <h1>Data Import</h1>
            <p>Download templates, fill your data, and upload them to migrate into Rentnexis.</p>
        </div>
    </div>

    <div class="import-steps">
        <div class="import-step"><strong>Step 1</strong><span>Download template</span></div>
        <div class="import-step"><strong>Step 2</strong><span>Fill data</span></div>
        <div class="import-step"><strong>Step 3</strong><span>Upload and preview</span></div>
        <div class="import-step"><strong>Step 4</strong><span>Fix errors and import</span></div>
    </div>

    <div class="import-note-banner">
        CSV templates are generated dynamically and open cleanly in Excel or Google Sheets. XLSX download is not included in this phase because no Excel package is installed.
    </div>

    <div class="import-grid">
        @foreach($cards as $card)
            <article class="import-card">
                <div class="import-card-top">
                    <strong>{{ $card['label'] }}</strong>
                    <p>{{ $card['download_label'] }}</p>
                </div>
                <div class="import-fields">
                    <span>Fields included</span>
                    <ul>
                        @foreach($card['fields'] as $field)
                            <li>{{ $field }}</li>
                        @endforeach
                    </ul>
                </div>
                @if($card['note'])
                    <div class="import-card-note">{{ $card['note'] }}</div>
                @endif
                <div class="import-card-actions">
                    <a href="{{ $card['download_href'] }}" class="import-action-btn import-action-btn-primary">Download Template</a>
                    @if($card['upload_available'] && $card['upload_href'])
                        <a href="{{ $card['upload_href'] }}" class="import-action-btn import-action-btn-secondary">Upload &amp; Preview</a>
                    @else
                        <button type="button" class="import-action-btn import-action-btn-secondary import-disabled-btn" disabled aria-disabled="true">Upload &amp; Preview</button>
                        <span class="import-coming-soon">Upload preview will be available soon.</span>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <div class="import-footer-copy">
        Import execution exists for selected modules already, but this landing page is focused on template download and preparation.
        </div>
</div>

<style>
    .import-shell{max-width:1180px;margin:0 auto;display:grid;gap:22px}
    .import-hero{background:linear-gradient(135deg,#fffaf0,#eff6ff 58%,#f8fafc);border:1px solid #dbeafe;border-radius:26px;padding:28px}
    .import-eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#1d4ed8}
    .import-hero h1{margin:10px 0 8px;font-size:34px;letter-spacing:-.04em}
    .import-hero p{margin:0;max-width:760px;color:#475569;line-height:1.65}
    .import-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .import-step{padding:16px 18px;border-radius:18px;background:#fff;border:1px solid #dbeafe;display:grid;gap:6px}
    .import-step strong{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#1d4ed8}
    .import-step span{font-weight:700;color:#0f172a}
    .import-note-banner{padding:14px 18px;border-radius:18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.6}
    .import-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
    .import-card{display:grid;gap:16px;padding:22px;border-radius:22px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 12px 30px rgba(15,23,42,.04)}
    .import-card-top{display:grid;gap:6px}
    .import-card-top strong{font-size:22px}
    .import-card-top p{margin:0;color:#475569;font-weight:700}
    .import-fields{display:grid;gap:8px}
    .import-fields span{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#64748b}
    .import-fields ul{margin:0;padding-left:18px;color:#475569;line-height:1.6}
    .import-card-note{padding:12px 14px;border-radius:16px;background:#eff6ff;color:#1e3a8a;line-height:1.6}
    .import-card-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:start}
    .import-action-btn{
        min-height:44px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:11px 14px;
        border-radius:14px;
        font-size:13px;
        font-weight:800;
        letter-spacing:.01em;
        text-decoration:none;
        transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease,background .15s ease,color .15s ease;
        box-sizing:border-box;
        width:100%;
    }
    .import-action-btn-primary{
        background:#0f172a;
        color:#fff;
        border:1px solid #0f172a;
        box-shadow:0 10px 18px rgba(15,23,42,.12);
    }
    .import-action-btn-primary:hover{transform:translateY(-1px);background:#111827;color:#fff}
    .import-action-btn-secondary{
        background:#f8fafc;
        color:#0f172a;
        border:1px solid #cbd5e1;
    }
    .import-action-btn-secondary:hover{transform:translateY(-1px);border-color:#94a3b8;color:#0f172a}
    .import-disabled-btn{
        opacity:.6;
        cursor:not-allowed;
        pointer-events:none;
        background:#f8fafc;
        color:#64748b;
        border-style:dashed;
        box-shadow:none;
    }
    .import-coming-soon{
        grid-column:2;
        font-size:12px;
        font-weight:700;
        line-height:1.45;
        letter-spacing:.01em;
        color:#64748b;
        text-align:center;
        padding:0 6px;
    }
    .import-footer-copy{color:#64748b;font-size:13px;line-height:1.6}
    @media (max-width:860px){.import-steps{grid-template-columns:1fr 1fr}}
    @media (max-width:768px){
        .import-hero{padding:22px}
        .import-hero h1{font-size:28px}
        .import-steps{grid-template-columns:1fr}
        .import-card-actions{grid-template-columns:1fr}
        .import-coming-soon{grid-column:1}
    }
</style>
@endsection
