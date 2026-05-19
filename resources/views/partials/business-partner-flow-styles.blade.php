@once
<style>
    .party-flow-shell {
        display: grid;
        gap: 12px;
    }
    .party-flow-toggle-wrap {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .party-flow-toggle {
        display: inline-grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 4px;
        padding: 4px;
        border-radius: 999px;
        border: 1px solid #dbe3ef;
        background: #f8fafc;
        min-width: min(100%, 360px);
    }
    .party-flow-select-native {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }
    .party-flow-option {
        border: none;
        border-radius: 999px;
        background: transparent;
        color: #475569;
        min-height: 40px;
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }
    .party-flow-option.is-active {
        background: #ffffff;
        color: #0f172a;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
    }
    .party-flow-hint {
        color: #64748b;
        font-size: 12px;
        line-height: 1.45;
    }
    .party-flow-rows {
        display: grid;
        gap: 10px;
    }
    .party-flow-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: end;
    }
    .party-flow-row[hidden],
    .party-flow-summary[hidden] {
        display: none !important;
    }
    .party-flow-summary {
        display: grid;
        gap: 8px;
        padding: 12px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
    }
    .party-flow-summary-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .party-flow-summary-title {
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748b;
    }
    .party-flow-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 9px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0369a1;
        font-size: 11px;
        font-weight: 800;
    }
    .party-flow-lines {
        display: grid;
        gap: 6px;
    }
    .party-flow-line {
        display: grid;
        gap: 2px;
    }
    .party-flow-line label {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .party-flow-line strong {
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.45;
    }
    .party-flow-meta {
        color: #475569;
        font-size: 12px;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .party-flow-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: 9px 12px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }
    .party-flow-link.is-disabled,
    .party-flow-link[aria-disabled="true"] {
        opacity: 0.45;
        cursor: not-allowed;
        pointer-events: none;
    }
    .party-flow-surface {
        padding: 12px 14px;
        border-radius: 14px;
        background: #fbfdff;
        border: 1px solid #e2e8f0;
    }
    .party-flow-surface[hidden] {
        display: none !important;
    }
    @media (max-width: 720px) {
        .party-flow-row {
            grid-template-columns: 1fr;
        }
        .party-flow-link {
            width: 100%;
        }
        .party-flow-toggle {
            width: 100%;
        }
    }
</style>
@endonce
