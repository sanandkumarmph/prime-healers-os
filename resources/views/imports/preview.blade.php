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

<div class="ph-import-page">
    <div class="ph-import-head">
        <div class="ph-import-head-copy">
            <a href="{{ $backUrl }}" class="ph-import-back">&larr; Back to Upload</a>
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>

        <div class="ph-import-actions page-header-actions">
            <a href="{{ $uploadNewUrl ?? $backUrl }}" class="ph-import-btn-secondary">Upload New File</a>
            @if(!empty($templateUrl))
                <a href="{{ $templateUrl }}" class="ph-import-btn-secondary">Download Template</a>
            @endif
            @if(!empty($importAction))
                <form method="POST" action="{{ $importAction }}">
                    @csrf
                    <button type="submit" class="ph-import-btn-primary{{ empty($importEnabled) ? ' is-disabled' : '' }}" @disabled(empty($importEnabled)) aria-disabled="{{ empty($importEnabled) ? 'true' : 'false' }}">{{ $importButtonLabel }}</button>
                </form>
            @else
                <button type="button" class="ph-import-btn-primary is-disabled" disabled aria-disabled="true">{{ $importButtonLabel }}</button>
            @endif
        </div>
    </div>

    <div class="ph-import-preview-helper">{{ $importHelperText ?? 'Import execution will be enabled after validation testing.' }}</div>

    @include('imports.partials.summary-cards', ['validCount' => $validCount, 'invalidCount' => $invalidCount, 'totalRows' => $totalRows ?? count($previewRows ?? [])])

    @include('imports.partials.required-banner', ['requiredFields' => $requiredFields ?? []])

    <div class="ph-import-layout">
        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Valid Rows Preview</h2>
                    <p>Showing up to the first 20 valid rows in a clean preview layout.</p>
                </div>
            </div>

            <div class="ph-import-table-wrap responsive-table-shell">
                <div class="responsive-table-scroll">
                    <table class="ph-import-table">
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

            <div class="ph-import-mobile-list">
                @forelse($validRowsPreview as $row)
                    <article class="ph-import-mobile-card">
                        <div class="ph-import-mobile-row">Row #{{ $row['row_number'] }}</div>
                        @foreach($previewColumns as $column)
                            <div class="ph-import-mobile-meta">
                                <span>{{ $formatLabel($column) }}</span>
                                <strong>{{ $resolvePreviewValue($row, $column, $headers ?? []) ?: '—' }}</strong>
                            </div>
                        @endforeach
                    </article>
                @empty
                    <div class="ph-import-empty">No valid rows available in this preview.</div>
                @endforelse
            </div>
        </section>

        <section class="ph-import-card">
            <div class="ph-import-panel-head">
                <div>
                    <h2>Invalid Rows</h2>
                    <p>Each invalid row shows the row number and the exact validation issues.</p>
                </div>
            </div>

            <div class="ph-import-invalid-list">
                @forelse($invalidRowsPreview as $row)
                    <article class="ph-import-invalid-card">
                        <strong>Row #{{ $row['row_number'] }}</strong>
                        <ul>
                            @foreach(($row['errors'] ?? []) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </article>
                @empty
                    <div class="ph-import-clean-state">No invalid rows in this preview.</div>
                @endforelse

                @foreach($runtimeErrors as $row)
                    <article class="ph-import-invalid-card">
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

@include('imports.partials.shared-styles')
@endsection
