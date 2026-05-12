@extends('layouts.app')

@section('content')
@php
    $previewColumns = $previewColumns ?? $headers ?? [];
    $fieldLabels = $fieldLabels ?? [];
    $validRowsPreview = collect($previewRows ?? [])->filter(fn ($row) => !empty($row['valid']))->take(20)->values();
    $invalidRowsPreview = collect($previewRows ?? [])->filter(fn ($row) => empty($row['valid']))->take(20)->values();
    $runtimeErrors = collect($runtimeErrors ?? []);
    $formatLabel = function (string $key) use ($fieldLabels) {
        if (isset($fieldLabels[$key])) {
            return $fieldLabels[$key];
        }

        return \Illuminate\Support\Str::of($key)->replace('_', ' ')->title()->toString();
    };
    $resolvePreviewValue = function (array $row, string $key, array $headers) {
        if (array_key_exists($key, $row['values'] ?? [])) {
            return $row['values'][$key];
        }

        foreach ($headers as $header) {
            $normalized = \Illuminate\Support\Str::of((string) $header)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            if ($normalized === $key) {
                return $row['values'][$header] ?? '';
            }
        }

        return '';
    };
@endphp

<div class="sales-import-preview-page">
    <div class="sales-import-preview-head">
        <div>
            <a href="{{ $backUrl }}" class="sales-import-preview-back">&larr; Back to Upload</a>
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>

        <div class="sales-import-preview-actions page-header-actions">
            <a href="{{ $backUrl }}" class="sales-import-preview-secondary">Upload New File</a>
            @if(!empty($templateUrl))
                <a href="{{ $templateUrl }}" class="sales-import-preview-secondary">Download Template</a>
            @endif
            @if(!empty($importAction))
                <form method="POST" action="{{ $importAction }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="sales-import-preview-primary{{ empty($importEnabled) ? ' is-disabled' : '' }}" @disabled(empty($importEnabled)) aria-disabled="{{ empty($importEnabled) ? 'true' : 'false' }}">{{ $importButtonLabel }}</button>
                </form>
            @else
                <button type="button" class="sales-import-preview-primary is-disabled" disabled aria-disabled="true">{{ $importButtonLabel }}</button>
            @endif
        </div>
    </div>

    <div class="sales-import-preview-helper">{{ $importHelperText ?? 'Import execution will be enabled after validation testing.' }}</div>

    <div class="sales-import-preview-stats">
        <article class="sales-import-preview-stat success">
            <span>Valid Rows</span>
            <strong>{{ $validCount }}</strong>
        </article>
        <article class="sales-import-preview-stat danger">
            <span>Invalid Rows</span>
            <strong>{{ $invalidCount }}</strong>
        </article>
        <article class="sales-import-preview-stat">
            <span>Total Rows</span>
            <strong>{{ $totalRows ?? count($previewRows ?? []) }}</strong>
        </article>
    </div>

    <div class="sales-import-preview-banner">
        <strong>Required fields</strong>
        <span>{{ implode(', ', $requiredFields ?? []) }}</span>
    </div>

    <div class="sales-import-preview-layout">
        <section class="sales-import-preview-panel">
            <div class="sales-import-preview-panel-head">
                <div>
                    <h2>Valid Rows Preview</h2>
                    <p>Showing up to the first 20 valid rows in a clean preview layout.</p>
                </div>
            </div>

            <div class="sales-import-preview-table-wrap responsive-table-shell">
                <div class="responsive-table-scroll">
                <table class="sales-import-preview-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            @foreach($previewColumns as $column)
                                <th>{{ $formatLabel($column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($validRowsPreview as $row)
                            <tr>
                                <td>#{{ $row['row_number'] }}</td>
                                @foreach($previewColumns as $column)
                                    <td>{{ $resolvePreviewValue($row, $column, $headers ?? []) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($previewColumns) + 1 }}">No valid rows available in this preview.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="sales-import-preview-mobile-list">
                @forelse($validRowsPreview as $row)
                    <article class="sales-import-preview-mobile-card">
                        <div class="sales-import-preview-mobile-row">Row #{{ $row['row_number'] }}</div>
                        @foreach($previewColumns as $column)
                            <div class="sales-import-preview-mobile-meta">
                                <span>{{ $formatLabel($column) }}</span>
                                <strong>{{ $resolvePreviewValue($row, $column, $headers ?? []) ?: '—' }}</strong>
                            </div>
                        @endforeach
                    </article>
                @empty
                    <div class="sales-import-preview-empty">No valid rows available in this preview.</div>
                @endforelse
            </div>
        </section>

        <section class="sales-import-preview-panel">
            <div class="sales-import-preview-panel-head">
                <div>
                    <h2>Invalid Rows</h2>
                    <p>Each invalid row shows the row number and the exact validation issues.</p>
                </div>
            </div>

            <div class="sales-import-preview-invalid-list">
                @forelse($invalidRowsPreview as $row)
                    <article class="sales-import-preview-invalid-card">
                        <strong>Row #{{ $row['row_number'] }}</strong>
                        <ul>
                            @foreach(($row['errors'] ?? []) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </article>
                @empty
                    <div class="sales-import-preview-clean-state">No invalid rows in this preview.</div>
                @endforelse

                @foreach($runtimeErrors as $row)
                    <article class="sales-import-preview-invalid-card">
                        <strong>Row #{{ $row['row_number'] }}</strong>
                        <ul>
                            @foreach(($row['errors'] ?? []) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</div>

<style>
    .sales-import-preview-page{max-width:1240px;margin:0 auto;display:grid;gap:18px;overflow-x:hidden}
    .sales-import-preview-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .sales-import-preview-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:800}
    .sales-import-preview-head h1{margin:10px 0 8px;font-size:34px;letter-spacing:-.04em;color:#0f172a}
    .sales-import-preview-head p{margin:0;max-width:720px;color:#64748b;line-height:1.65}
    .sales-import-preview-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .sales-import-preview-primary,
    .sales-import-preview-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border-radius:14px;font-size:13px;font-weight:800;letter-spacing:.01em;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
    .sales-import-preview-secondary{border:1px solid #cbd5e1;background:#fff;color:#0f172a;box-shadow:0 8px 18px rgba(15,23,42,.05)}
    .sales-import-preview-secondary:hover{transform:translateY(-1px);border-color:#94a3b8}
    .sales-import-preview-primary{border:1px solid #16a34a;background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.2)}
    .sales-import-preview-primary.is-disabled{border-color:#cbd5e1;background:linear-gradient(180deg,#e2e8f0 0%,#cbd5e1 100%);color:#64748b;box-shadow:none;cursor:not-allowed}
    .sales-import-preview-helper{padding:12px 16px;border-radius:16px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;font-weight:700;line-height:1.55}
    .sales-import-preview-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .sales-import-preview-stat{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px 20px;display:grid;gap:8px}
    .sales-import-preview-stat.success{background:#f0fdf4;border-color:#bbf7d0}
    .sales-import-preview-stat.danger{background:#fef2f2;border-color:#fecaca}
    .sales-import-preview-stat span{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#64748b}
    .sales-import-preview-stat strong{font-size:30px;color:#0f172a}
    .sales-import-preview-banner{display:grid;gap:4px;padding:14px 16px;border-radius:16px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a}
    .sales-import-preview-banner strong{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    .sales-import-preview-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px}
    .sales-import-preview-panel{min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:22px;display:grid;gap:16px}
    .sales-import-preview-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .sales-import-preview-panel-head h2{margin:0;font-size:24px;color:#0f172a}
    .sales-import-preview-panel-head p{margin:6px 0 0;color:#64748b;line-height:1.55}
    .sales-import-preview-table-wrap{overflow:auto;max-width:100%;max-height:620px;border:1px solid #e2e8f0;border-radius:18px}
    .sales-import-preview-table{width:100%;min-width:980px;border-collapse:collapse;table-layout:fixed}
    .sales-import-preview-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .sales-import-preview-table th,
    .sales-import-preview-table td{padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;font-size:13px;line-height:1.45;word-break:break-word;overflow-wrap:anywhere}
    .sales-import-preview-table th{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
    .sales-import-preview-table td:first-child,
    .sales-import-preview-table th:first-child{width:88px}
    .sales-import-preview-mobile-list{display:none}
    .sales-import-preview-mobile-card{display:grid;gap:10px;padding:16px;border-radius:18px;border:1px solid #e2e8f0;background:#f8fafc}
    .sales-import-preview-mobile-row{font-size:13px;font-weight:800;color:#0f172a}
    .sales-import-preview-mobile-meta{display:grid;gap:4px}
    .sales-import-preview-mobile-meta span{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
    .sales-import-preview-mobile-meta strong{font-size:14px;color:#0f172a;line-height:1.45;overflow-wrap:anywhere}
    .sales-import-preview-invalid-list{display:grid;gap:12px;max-height:620px;overflow:auto;padding-right:2px}
    .sales-import-preview-invalid-card{padding:14px 16px;border-radius:18px;background:#fef2f2;border:1px solid #fecaca}
    .sales-import-preview-invalid-card strong{display:block;margin-bottom:8px;color:#7f1d1d}
    .sales-import-preview-invalid-card ul{margin:0;padding-left:18px;color:#991b1b;line-height:1.6}
    .sales-import-preview-clean-state,
    .sales-import-preview-empty{padding:16px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-weight:700}
    @media (max-width:980px){
        .sales-import-preview-stats{grid-template-columns:1fr}
        .sales-import-preview-layout{grid-template-columns:1fr}
    }
    @media (max-width:760px){
        .sales-import-preview-head h1{font-size:28px}
        .sales-import-preview-actions{display:grid;width:100%}
        .sales-import-preview-secondary,
        .sales-import-preview-primary{width:100%}
        .sales-import-preview-table-wrap{display:none}
        .sales-import-preview-mobile-list{display:grid;gap:12px}
        .sales-import-preview-panel{padding:18px}
    }
</style>
@endsection
