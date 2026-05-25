<style>
    .ph-import-page{max-width:1240px;margin:0 auto;display:grid;gap:20px;overflow-x:hidden}
    .ph-import-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .ph-import-head-copy{min-width:0;display:grid;gap:8px}
    .ph-import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:800}
    .ph-import-head h1{margin:0;font-size:34px;letter-spacing:-.04em;color:#0f172a}
    .ph-import-head p{margin:0;max-width:760px;color:#64748b;line-height:1.65}
    .ph-import-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .ph-import-actions form{margin:0}
    .ph-import-btn-primary,
    .ph-import-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border-radius:14px;font-size:13px;font-weight:800;letter-spacing:.01em;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease,filter .15s ease}
    .ph-import-btn-primary{border:1px solid #16a34a;background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff;box-shadow:0 12px 24px rgba(22,163,74,.2)}
    .ph-import-btn-primary:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 16px 28px rgba(22,163,74,.26);filter:saturate(1.05)}
    .ph-import-btn-primary.is-disabled,
    .ph-import-btn-primary:disabled{border-color:#cbd5e1;background:linear-gradient(180deg,#e2e8f0 0%,#cbd5e1 100%);color:#64748b;box-shadow:none;cursor:not-allowed}
    .ph-import-btn-secondary{border:1px solid #cbd5e1;background:#fff;color:#0f172a;box-shadow:0 8px 18px rgba(15,23,42,.05)}
    .ph-import-btn-secondary:hover{transform:translateY(-1px);border-color:#94a3b8}
    .ph-import-upload-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(300px,.85fr);gap:18px}
    .ph-import-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px}
    .ph-import-card{min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:22px;display:grid;gap:16px;box-shadow:0 12px 28px rgba(15,23,42,.04)}
    .ph-import-side-panel{display:grid;gap:18px}
    .ph-import-section-copy{display:grid;gap:8px}
    .ph-import-kicker{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#2563eb}
    .ph-import-card h2{margin:0;font-size:24px;color:#0f172a}
    .ph-import-card p{margin:0;color:#64748b;line-height:1.6}
    .ph-import-upload-form{display:grid;gap:14px}
    .ph-import-dropzone{display:grid;gap:8px;justify-items:start;padding:24px;border:1.5px dashed #93c5fd;border-radius:22px;background:linear-gradient(180deg,#f8fbff 0%,#eff6ff 100%);cursor:pointer}
    .ph-import-dropzone:hover{border-color:#60a5fa;background:linear-gradient(180deg,#f0f7ff 0%,#e0efff 100%)}
    .ph-import-dropzone-icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:14px;background:#dbeafe;color:#1d4ed8;font-size:28px;font-weight:500}
    .ph-import-dropzone-title{font-size:18px;font-weight:800;color:#0f172a}
    .ph-import-dropzone-copy{font-size:14px;line-height:1.55;color:#64748b}
    .ph-import-file-input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}
    .ph-import-file-note{font-size:13px;font-weight:700;color:#475569}
    .ph-import-error{font-size:13px;font-weight:800;color:#b91c1c}
    .ph-import-helper-banner{display:grid;gap:4px;padding:14px 16px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
    .ph-import-helper-banner strong{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0f766e}
    .ph-import-field-chips{display:flex;flex-wrap:wrap;gap:8px}
    .ph-import-field-chips span{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800}
    .ph-import-flow{margin:0;padding-left:18px;color:#475569;line-height:1.7}
    .ph-import-meta{padding:14px 16px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;line-height:1.6}
    .ph-import-meta strong{color:#0f172a}
    .ph-import-preview-helper{padding:12px 16px;border-radius:16px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;font-weight:700;line-height:1.55}
    .ph-import-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .ph-import-stat{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px 20px;display:grid;gap:8px}
    .ph-import-stat.success{background:#f0fdf4;border-color:#bbf7d0}
    .ph-import-stat.danger{background:#fef2f2;border-color:#fecaca}
    .ph-import-stat.warning{background:#fffbeb;border-color:#fde68a}
    .ph-import-stat.info{background:#eff6ff;border-color:#bfdbfe}
    .ph-import-stat span{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#64748b}
    .ph-import-stat strong{font-size:30px;color:#0f172a}
    .ph-import-banner{display:grid;gap:4px;padding:14px 16px;border-radius:16px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a}
    .ph-import-banner strong{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    .ph-import-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .ph-import-panel-head h2{margin:0;font-size:24px;color:#0f172a}
    .ph-import-panel-head p{margin:6px 0 0;color:#64748b;line-height:1.55}
    .ph-import-table-wrap{overflow:auto;max-width:100%;max-height:620px;border:1px solid #e2e8f0;border-radius:18px}
    .ph-import-table{width:100%;min-width:980px;border-collapse:collapse;table-layout:fixed}
    .ph-import-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .ph-import-table th,
    .ph-import-table td{padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;font-size:13px;line-height:1.45;word-break:break-word;overflow-wrap:anywhere}
    .ph-import-table th{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
    .ph-import-table pre{margin:0;white-space:pre-wrap;font-size:12px;line-height:1.55;color:#334155}
    .ph-import-mobile-list{display:none}
    .ph-import-mobile-card{display:grid;gap:10px;padding:16px;border-radius:18px;border:1px solid #e2e8f0;background:#f8fafc}
    .ph-import-mobile-row{font-size:13px;font-weight:800;color:#0f172a}
    .ph-import-mobile-meta{display:grid;gap:4px}
    .ph-import-mobile-meta span{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
    .ph-import-mobile-meta strong{font-size:14px;color:#0f172a;line-height:1.45;overflow-wrap:anywhere}
    .ph-import-invalid-list{display:grid;gap:12px;max-height:620px;overflow:auto;padding-right:2px}
    .ph-import-invalid-card{padding:14px 16px;border-radius:18px;background:#fef2f2;border:1px solid #fecaca}
    .ph-import-invalid-card strong{display:block;margin-bottom:8px;color:#7f1d1d}
    .ph-import-invalid-card ul{margin:0;padding-left:18px;color:#991b1b;line-height:1.6}
    .ph-import-clean-state,
    .ph-import-empty{padding:16px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-weight:700}
    .ph-import-warning-banner{padding:16px 18px;border-radius:18px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;display:grid;gap:4px}
    .ph-import-result-banner{padding:14px 18px;border-radius:18px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a}
    .ph-import-reason-chip-row{display:flex;flex-wrap:wrap;gap:10px}
    .ph-import-reason-chip{display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;background:#f8fafc;border:1px solid #cbd5e1;color:#334155;font-size:12px;font-weight:800}
    .ph-import-result-details-grid{display:grid;gap:14px}
    .ph-import-result-details{border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;padding:0 16px}
    .ph-import-result-details summary{cursor:pointer;list-style:none;padding:16px 0;font-size:14px;font-weight:800;color:#0f172a}
    .ph-import-result-details summary::-webkit-details-marker{display:none}
    .ph-import-result-items{display:grid;gap:12px;padding:0 0 16px}
    .ph-import-result-item{padding:14px 16px;border-radius:16px;background:#fff;border:1px solid #e2e8f0}
    .ph-import-result-item-failed{border-color:#fecaca;background:#fff7f7}
    .ph-import-result-item-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .ph-import-result-badge{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
    .ph-import-result-badge.is-danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
    .ph-import-result-identifier{margin-top:6px;color:#475569;font-size:13px;font-weight:700;overflow-wrap:anywhere}
    .ph-import-result-item p{margin:10px 0 0;color:#0f172a;line-height:1.55}
    .ph-import-result-item ul{margin:10px 0 0;padding-left:18px;color:#64748b;line-height:1.55}
    .ph-import-result-empty{padding:14px 0 6px;color:#475569;font-weight:700}
    .ph-import-guidance-card{margin-top:12px;padding:14px;border-radius:14px;background:#fff;border:1px solid #fed7aa;display:grid;gap:12px}
    .ph-import-guidance-copy{display:grid;gap:6px;color:#7c2d12}
    .ph-import-guidance-copy p{margin:4px 0 0;line-height:1.55}
    .ph-import-guidance-tags{display:flex;gap:8px;flex-wrap:wrap}
    .ph-import-guidance-tags span{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
    .ph-import-guidance-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:12px;border:1px solid #fdba74;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:800;text-decoration:none;justify-self:start}
    .ph-import-guidance-btn:hover{background:#ffedd5}
    @media (max-width:980px){
        .ph-import-layout,
        .ph-import-upload-grid,
        .ph-import-stats{grid-template-columns:1fr}
    }
    @media (max-width:760px){
        .ph-import-head h1{font-size:28px}
        .ph-import-actions{display:grid;width:100%}
        .ph-import-actions form,
        .ph-import-btn-secondary,
        .ph-import-btn-primary{width:100%}
        .ph-import-table-wrap{display:none}
        .ph-import-mobile-list{display:grid;gap:12px}
        .ph-import-card{padding:18px}
    }
</style>
