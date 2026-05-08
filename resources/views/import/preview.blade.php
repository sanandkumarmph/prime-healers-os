@extends('layouts.app')

@section('content')
@php
    $validRows = collect($preview['valid_rows'] ?? []);
    $invalidRows = collect($preview['invalid_rows'] ?? []);
    $assetPrecheck = $preview['asset_precheck']['untracked_products'] ?? [];
    $alreadyImported = !empty($preview['imported_at']);
@endphp
<div class="import-shell">
    <div class="import-head">
        <div>
            <a href="{{ route('imports.mapping', $module) }}" class="import-back">← Back to Mapping</a>
            <h1>Validation Preview</h1>
            <p>Review valid and invalid rows before executing the import.</p>
        </div>
        <div class="import-head-actions">
            @if($errorReportAvailable)
                <a href="{{ route('imports.error-report', $module) }}" class="ops-btn-light">Download Error Report</a>
            @endif
            <form method="POST" action="{{ route('imports.execute', $module) }}">
                @csrf
                <button type="submit" class="import-primary-btn" @disabled($validRows->isEmpty() || $alreadyImported)>{{ $alreadyImported ? 'Import Completed' : 'Import Valid Rows' }}</button>
            </form>
        </div>
    </div>

    @if($lastResult)
        <div class="import-result-banner">
            <strong>Import finished.</strong>
            Processed {{ $lastResult['processed'] ?? 0 }}, created {{ $lastResult['created'] ?? 0 }}, updated {{ $lastResult['updated'] ?? 0 }}, skipped {{ $lastResult['skipped'] ?? 0 }}.
        </div>
    @elseif($alreadyImported)
        <div class="import-result-banner">
            <strong>Import already completed.</strong>
            This preview has already been executed, so duplicate import is blocked.
        </div>
    @endif

    <div class="import-stat-grid">
        <div class="import-stat success">
            <span>Valid Rows</span>
            <strong>{{ $validRows->count() }}</strong>
        </div>
        <div class="import-stat danger">
            <span>Invalid Rows</span>
            <strong>{{ $invalidRows->count() }}</strong>
        </div>
        <div class="import-stat">
            <span>Total Rows</span>
            <strong>{{ $preview['row_count'] ?? 0 }}</strong>
        </div>
    </div>

    @if($module === 'assets' && !empty($assetPrecheck))
        <div class="import-warning-banner">
            <strong>{{ count($assetPrecheck) }} {{ count($assetPrecheck) === 1 ? 'product needs' : 'products need' }} stock mode update before import.</strong>
            <span>These products are currently untracked, so asset import cannot continue until they are switched to a tracked mode.</span>
        </div>
    @endif

    <div class="import-preview-grid">
        <section class="import-panel">
            <h2>Valid Rows Preview</h2>
            <div class="import-table-wrap">
                <table class="import-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Action</th>
                            <th>Mapped Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($validRows->take(20) as $row)
                            <tr>
                                <td>#{{ $row['row_number'] }}</td>
                                <td>{{ $row['mapped']['import_action'] ?? ($row['payload']['import_action'] ?? 'Create') }}</td>
                                <td><pre>{{ json_encode($row['mapped'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                            </tr>
                        @empty
                            <tr><td colspan="3">No valid rows available.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="import-panel">
            <h2>Invalid Rows</h2>
            <div class="import-invalid-list">
                @forelse($invalidRows->take(20) as $row)
                    <article class="import-invalid-item">
                        <strong>Row #{{ $row['row_number'] }}</strong>
                        <ul>
                            @foreach(($row['errors'] ?? []) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        @if(($row['guidance']['type'] ?? null) === 'untracked_product')
                            <div class="import-guidance-card">
                                <div class="import-guidance-copy">
                                    <div><strong>Product:</strong> {{ $row['guidance']['product_name'] }}</div>
                                    <div><strong>Current Mode:</strong> {{ $row['guidance']['current_mode_label'] }}</div>
                                    <p>{{ $row['guidance']['message'] }}</p>
                                    <div class="import-guidance-tags">
                                        @foreach(($row['guidance']['suggested_modes'] ?? []) as $mode)
                                            <span>{{ $mode }}</span>
                                        @endforeach
                                    </div>
                                </div>
                                @if(\Illuminate\Support\Facades\Route::has('products.index'))
                                    <a href="{{ route('products.index') }}" class="import-guidance-btn">Open Product Master</a>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <p style="margin:0;color:#166534;font-weight:700;">No validation errors. This file is ready to import.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>

<style>
    .import-shell{max-width:1240px;margin:0 auto;display:grid;gap:20px}
    .import-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .import-head-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .import-head h1{margin:10px 0 6px;font-size:30px;letter-spacing:-.04em}
    .import-head p{margin:0;color:#64748b}
    .import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:700}
    .import-result-banner{padding:14px 18px;border-radius:18px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a}
    .import-warning-banner{padding:16px 18px;border-radius:18px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;display:grid;gap:4px}
    .import-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .import-stat{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px 20px;display:grid;gap:8px}
    .import-stat.success{border-color:#bbf7d0;background:#f0fdf4}
    .import-stat.danger{border-color:#fecaca;background:#fef2f2}
    .import-stat span{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700}
    .import-stat strong{font-size:30px}
    .import-preview-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:18px}
    .import-panel{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:24px;display:grid;gap:16px}
    .import-table-wrap{overflow:auto}
    .import-table{width:100%;border-collapse:collapse;min-width:620px}
    .import-table th,.import-table td{padding:12px 14px;border-bottom:1px solid #e2e8f0;vertical-align:top;text-align:left}
    .import-table pre{margin:0;white-space:pre-wrap;font-size:12px;line-height:1.55;color:#334155}
    .import-invalid-list{display:grid;gap:12px}
    .import-invalid-item{padding:14px;border-radius:16px;background:#fef2f2;border:1px solid #fecaca}
    .import-invalid-item strong{display:block;margin-bottom:8px}
    .import-invalid-item ul{margin:0;padding-left:18px;color:#991b1b;line-height:1.5}
    .import-guidance-card{margin-top:12px;padding:14px;border-radius:14px;background:#fff;border:1px solid #fed7aa;display:grid;gap:12px}
    .import-guidance-copy{display:grid;gap:6px;color:#7c2d12}
    .import-guidance-copy p{margin:4px 0 0;line-height:1.55}
    .import-guidance-tags{display:flex;gap:8px;flex-wrap:wrap}
    .import-guidance-tags span{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
    .import-guidance-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #fdba74;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:800;text-decoration:none;justify-self:start}
    .import-guidance-btn:hover{background:#ffedd5}
    .import-primary-btn{
        display:inline-flex;align-items:center;justify-content:center;gap:8px;
        min-height:46px;padding:0 20px;border:1px solid #16a34a;border-radius:14px;
        background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff;
        font-size:14px;font-weight:800;letter-spacing:.01em;box-shadow:0 12px 24px rgba(22,163,74,.22);
        transition:transform .15s ease,box-shadow .15s ease,filter .15s ease
    }
    .import-primary-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 16px 28px rgba(22,163,74,.28);filter:saturate(1.05)}
    .import-primary-btn:focus-visible{outline:3px solid rgba(34,197,94,.22);outline-offset:2px}
    .import-primary-btn:disabled{
        border-color:#cbd5e1;background:linear-gradient(180deg,#e2e8f0 0%,#cbd5e1 100%);
        color:#64748b;box-shadow:none;cursor:not-allowed;transform:none;filter:none
    }
    @media (max-width:960px){.import-stat-grid{grid-template-columns:1fr}.import-preview-grid{grid-template-columns:1fr}}
</style>
@endsection
