@extends('layouts.app')

@section('content')
<div class="import-shell">
    <div class="import-head">
        <div>
            <a href="{{ route('imports.module', $module) }}" class="import-back">Back to Upload</a>
            <h1>Map Columns</h1>
            <p>Map each Prime Healers OS field to a column in <strong>{{ $upload['original_name'] ?? 'uploaded file' }}</strong>.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('imports.preview.build', $module) }}" class="import-map-form">
        @csrf
        <div class="import-panel">
            <div class="import-map-summary">
                <div><strong>Headers:</strong> {{ count($upload['headers'] ?? []) }}</div>
                <div><strong>Rows:</strong> {{ $upload['row_count'] ?? 0 }}</div>
            </div>
            @error('mapping')
                <div class="import-error">{{ $message }}</div>
            @enderror
            <div class="import-map-grid">
                @foreach($fields as $fieldKey => $field)
                    <label class="import-map-row">
                        <span>
                            <strong>{{ $field['label'] }}</strong>
                            @if(!empty($field['required']))
                                <small>Required</small>
                            @else
                                <small>Optional</small>
                            @endif
                        </span>
                        <select name="mapping[{{ $fieldKey }}]" class="ops-select">
                            <option value="">Do not import</option>
                            @foreach(($upload['headers'] ?? []) as $header)
                                <option value="{{ $header }}" @selected(old('mapping.' . $fieldKey, $suggestedMapping[$fieldKey] ?? null) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                        @if(!empty($field['sample']) || !empty($field['accepted_values']) || !empty($field['description']))
                            <div class="import-field-guidance">
                                @if(!empty($field['sample']))
                                    <span><b>Sample:</b> {{ $field['sample'] }}</span>
                                @endif
                                @if(!empty($field['accepted_values']))
                                    <span><b>Accepted:</b> {{ $field['accepted_values'] }}</span>
                                @endif
                                @if(!empty($field['description']))
                                    <small>{{ $field['description'] }}</small>
                                @endif
                            </div>
                        @endif
                    </label>
                @endforeach
            </div>
            <div class="import-map-actions">
                <button type="submit" class="import-primary-btn">Build Preview</button>
            </div>
        </div>
    </form>
</div>

<style>
    .import-shell{max-width:1180px;margin:0 auto;display:grid;gap:20px}
    .import-head h1{margin:10px 0 6px;font-size:30px;letter-spacing:-.04em}
    .import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:700}
    .import-head p{margin:0;color:#64748b}
    .import-panel{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:24px;display:grid;gap:18px}
    .import-map-summary{display:flex;gap:18px;flex-wrap:wrap;color:#475569}
    .import-map-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
    .import-map-row{display:grid;gap:8px;padding:16px;border-radius:18px;background:#f8fafc;border:1px solid #e2e8f0}
    .import-map-row span{display:flex;justify-content:space-between;gap:12px;align-items:center}
    .import-map-row strong{font-size:14px}
    .import-map-row small{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
    .import-field-guidance{display:grid;gap:4px;padding-top:2px;color:#64748b;font-size:12px;line-height:1.35}.import-field-guidance b{color:#334155}.import-field-guidance small{font-size:12px;text-transform:none;letter-spacing:0;color:#64748b}
    .import-map-actions{display:flex;justify-content:flex-end}
    .import-error{font-size:13px;color:#b91c1c;font-weight:700}
    .import-primary-btn{
        display:inline-flex;align-items:center;justify-content:center;gap:8px;
        min-height:46px;padding:0 20px;border:1px solid #16a34a;border-radius:14px;
        background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff;
        font-size:14px;font-weight:800;letter-spacing:.01em;box-shadow:0 12px 24px rgba(22,163,74,.22);
        transition:transform .15s ease,box-shadow .15s ease,filter .15s ease
    }
    .import-primary-btn:hover{transform:translateY(-1px);box-shadow:0 16px 28px rgba(22,163,74,.28);filter:saturate(1.05)}
    .import-primary-btn:focus-visible{outline:3px solid rgba(34,197,94,.22);outline-offset:2px}
</style>
@endsection
