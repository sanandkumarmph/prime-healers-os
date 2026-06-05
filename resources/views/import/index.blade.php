@extends('layouts.app')

@section('content')
@php
    $capabilityMap = [
        'customers' => ['City Aware', 'Business Partner Support', 'GST Ready'],
        'products' => ['Product Master Ready', 'Sellable + Rentable', 'GST Ready'],
        'assets' => ['City Aware', 'Warehouse Support', 'Rental Asset Ready'],
        'rentals' => ['City Aware', 'Vendor Fulfilment', 'Warehouse Support', 'Business Partner Support'],
        'vendors' => ['City Aware', 'Fulfilment Ready'],
        'staff' => ['City Aware', 'Role Support'],
        'sales' => ['City Aware', 'Vendor Fulfilment', 'Business Partner Support', 'GST Ready'],
        'opening-balances' => ['Customer Matching', 'Legacy Balance Support'],
    ];
@endphp

<div class="import-shell" x-data="{ openFieldsKey: null }">
    <div class="import-hero">
        <div>
            <div class="import-eyebrow">Data Import</div>
            <h1>Data Import</h1>
            <p>Download guided workbooks, fill your data, then preview and import with field-level validation feedback.</p>
        </div>
    </div>

    <div class="import-steps">
        <div class="import-step"><strong>Step 1</strong><span>Download template</span></div>
        <div class="import-step"><strong>Step 2</strong><span>Fill data</span></div>
        <div class="import-step"><strong>Step 3</strong><span>Preview import</span></div>
        <div class="import-step"><strong>Step 4</strong><span>Import safely</span></div>
    </div>

    <div class="import-note-banner">
        Every template includes a guidance sheet with required vs optional fields, sample values, and accepted-value help for the latest PHOS business architecture.
    </div>

    <div class="import-grid">
        @foreach($cards as $card)
            @php
                $capabilities = $capabilityMap[$card['key']] ?? ['Guidance Sheet Included'];
                $fieldCount = count($card['fields'] ?? []);
                $modalId = 'fields-modal-' . $card['key'];
            @endphp
            <article class="import-card">
                <div class="import-card-top">
                    <div class="import-card-copy">
                        <strong>{{ $card['label'] }}</strong>
                        <p>{{ number_format($fieldCount) }} Fields Included</p>
                    </div>
                    <span class="import-card-pill">{{ strtoupper($card['key']) }}</span>
                </div>

                <div class="import-capability-list">
                    @foreach(array_slice($capabilities, 0, 4) as $capability)
                        <span class="import-capability-chip">{{ $capability }}</span>
                    @endforeach
                </div>

                @if($card['note'])
                    <div class="import-card-note">{{ $card['note'] }}</div>
                @endif

                <div class="import-card-actions">
                    <a href="{{ $card['download_href'] }}" class="import-action-btn import-action-btn-primary">Download Template</a>
                    @if($card['upload_available'] && $card['upload_href'])
                        <a href="{{ $card['upload_href'] }}" class="import-action-btn import-action-btn-secondary">Import</a>
                    @else
                        <button type="button" class="import-action-btn import-action-btn-secondary import-disabled-btn" disabled aria-disabled="true">Import</button>
                    @endif
                    <button
                        type="button"
                        class="import-action-btn import-action-btn-tertiary"
                        @click="openFieldsKey = '{{ $card['key'] }}'"
                        aria-controls="{{ $modalId }}"
                    >
                        View Fields
                    </button>
                </div>

                @unless($card['upload_available'] && $card['upload_href'])
                    <div class="import-coming-soon">Upload preview will be available soon.</div>
                @endunless

                <div
                    x-cloak
                    x-show="openFieldsKey === '{{ $card['key'] }}'"
                    class="import-modal"
                    id="{{ $modalId }}"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="{{ $modalId }}-title"
                    @keydown.escape.window="openFieldsKey = null"
                >
                    <div class="import-modal-backdrop" @click="openFieldsKey = null"></div>
                    <div class="import-modal-panel">
                        <div class="import-modal-head">
                            <div>
                                <div class="import-eyebrow">Template Fields</div>
                                <h2 id="{{ $modalId }}-title">{{ $card['label'] }}</h2>
                                <p>{{ number_format($fieldCount) }} fields, with guidance sheet and sample rows included.</p>
                            </div>
                            <button type="button" class="import-modal-close" @click="openFieldsKey = null" aria-label="Close fields modal">Close</button>
                        </div>

                        <div class="import-modal-sections">
                            <section class="import-modal-section">
                                <h3>Capabilities</h3>
                                <div class="import-capability-list">
                                    @foreach($capabilities as $capability)
                                        <span class="import-capability-chip">{{ $capability }}</span>
                                    @endforeach
                                </div>
                            </section>

                            <section class="import-modal-section">
                                <h3>Fields Included</h3>
                                <div class="import-fields-grid">
                                    @foreach($card['fields'] as $field)
                                        <div class="import-field-chip">{{ $field }}</div>
                                    @endforeach
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <div class="import-footer-copy">
        Import execution exists for selected modules already, but this landing page stays focused on template download, preparation, and preview entry.
    </div>
</div>

<style>
    [x-cloak]{display:none!important}
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
    .import-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;align-items:stretch}
    .import-card{display:flex;flex-direction:column;gap:16px;min-height:320px;padding:22px;border-radius:22px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 12px 30px rgba(15,23,42,.04)}
    .import-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
    .import-card-copy{display:grid;gap:6px}
    .import-card-top strong{font-size:22px;line-height:1.15}
    .import-card-top p{margin:0;color:#475569;font-weight:700}
    .import-card-pill{display:inline-flex;align-items:center;justify-content:center;padding:7px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;font-size:11px;font-weight:800;letter-spacing:.08em;white-space:nowrap}
    .import-capability-list{display:flex;flex-wrap:wrap;gap:8px}
    .import-capability-chip{display:inline-flex;align-items:center;padding:7px 10px;border-radius:999px;background:#f8fafc;border:1px solid #dbeafe;color:#334155;font-size:12px;font-weight:700;line-height:1.2}
    .import-card-note{padding:12px 14px;border-radius:16px;background:#eff6ff;color:#1e3a8a;line-height:1.55;font-size:13px}
    .import-card-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:start;margin-top:auto}
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
    .import-action-btn-tertiary{
        grid-column:1 / -1;
        background:#fff;
        color:#334155;
        border:1px dashed #cbd5e1;
    }
    .import-action-btn-tertiary:hover{transform:translateY(-1px);border-color:#94a3b8;color:#0f172a}
    .import-disabled-btn{
        opacity:.6;
        cursor:not-allowed;
        pointer-events:none;
        background:#f8fafc;
        color:#64748b;
        border-style:dashed;
        box-shadow:none;
    }
    .import-coming-soon{font-size:12px;font-weight:700;line-height:1.45;letter-spacing:.01em;color:#64748b;text-align:center;padding:0 6px}
    .import-modal{position:fixed;inset:0;z-index:80;display:grid;place-items:center;padding:24px}
    .import-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(3px)}
    .import-modal-panel{position:relative;z-index:1;width:min(760px,100%);max-height:min(80vh,760px);overflow:auto;border-radius:24px;background:#fff;border:1px solid #dbeafe;box-shadow:0 32px 80px rgba(15,23,42,.18);padding:24px}
    .import-modal-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}
    .import-modal-head h2{margin:10px 0 8px;font-size:26px;line-height:1.15}
    .import-modal-head p{margin:0;color:#475569;line-height:1.6}
    .import-modal-close{border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:14px;padding:10px 14px;font-weight:800;font-size:13px}
    .import-modal-sections{display:grid;gap:18px;margin-top:22px}
    .import-modal-section{display:grid;gap:10px}
    .import-modal-section h3{margin:0;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
    .import-fields-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .import-field-chip{padding:10px 12px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;font-size:13px;font-weight:700;line-height:1.4}
    .import-footer-copy{color:#64748b;font-size:13px;line-height:1.6}
    @media (max-width:860px){.import-steps{grid-template-columns:1fr 1fr}}
    @media (max-width:768px){
        .import-hero{padding:22px}
        .import-hero h1{font-size:28px}
        .import-steps{grid-template-columns:1fr}
        .import-card-actions{grid-template-columns:1fr}
        .import-modal{padding:14px}
        .import-modal-panel{padding:18px}
        .import-modal-head{flex-direction:column}
        .import-card{min-height:auto}
    }
</style>
@endsection
