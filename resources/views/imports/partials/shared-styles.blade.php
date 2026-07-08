<style>
    .ph-import-page{max-width:1240px;margin:0 auto;display:grid;gap:14px;overflow-x:hidden}
    .ph-import-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .ph-import-head-copy{min-width:0;display:grid;gap:5px}
    .ph-import-back{color:#2563eb;text-decoration:none;font-size:13px;font-weight:800}
    .ph-import-head h1{margin:0;font-size:28px;letter-spacing:-.035em;color:#0f172a}
    .ph-import-head p{margin:0;max-width:760px;color:#64748b;font-size:14px;line-height:1.45}
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
    .ph-import-upload-grid{display:grid;grid-template-columns:minmax(0,1.28fr) minmax(280px,.72fr);gap:12px}
    .ph-import-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px}
    .ph-import-card{min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px;display:grid;gap:12px;box-shadow:0 10px 22px rgba(15,23,42,.035)}
    .ph-import-side-panel{display:grid;gap:12px}
    .ph-import-section-copy{display:grid;gap:5px}
    .ph-import-kicker{font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#2563eb}
    .ph-import-card h2{margin:0;font-size:21px;color:#0f172a}
    .ph-import-card p{margin:0;color:#64748b;font-size:14px;line-height:1.42}
    .ph-import-upload-form{display:grid;gap:10px}
    .ph-import-dropzone{min-height:138px;display:grid;gap:5px;place-items:center;text-align:center;padding:14px 18px;border:1.5px dashed #93c5fd;border-radius:16px;background:linear-gradient(180deg,#f8fbff 0%,#eff6ff 100%);cursor:pointer;transition:border-color .15s ease,background .15s ease,transform .15s ease}
    .ph-import-dropzone:hover,
    .ph-import-dropzone.is-dragover{border-color:#2563eb;background:#eff6ff}
    .ph-import-dropzone.is-dragover{transform:translateY(-1px)}
    .ph-import-dropzone-icon{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:14px;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:900;letter-spacing:.04em}
    .ph-import-dropzone-title{font-size:17px;font-weight:900;color:#0f172a;line-height:1.2}
    .ph-import-dropzone-copy{font-size:13px;line-height:1.35;color:#64748b}
    .ph-import-browse-text{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;margin-left:4px;border-radius:10px;background:#fff;border:1px solid #bfdbfe;color:#1d4ed8;font-weight:900}
    .ph-import-dropzone-format{font-size:12px;font-weight:800;color:#64748b}
    .ph-import-file-input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}
    .ph-import-file-note{font-size:13px;font-weight:700;color:#475569}
    .ph-import-selected-file{display:grid;grid-template-columns:42px minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:12px;border-radius:16px;background:#f8fafc;border:1px solid #dbe4f0;color:#334155}
    .ph-import-selected-file[hidden]{display:none!important}
    .ph-import-selected-icon{width:42px;height:42px;border-radius:13px;background:#dcfce7;color:#15803d;display:grid;place-items:center;font-size:11px;font-weight:900}
    .ph-import-selected-copy{display:grid;gap:2px;min-width:0}
    .ph-import-selected-copy strong{font-size:14px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ph-import-selected-copy small{font-size:12px;color:#64748b}
    .ph-import-file-action{height:36px;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:11px;padding:0 12px;font-size:12px;font-weight:900;cursor:pointer}
    .ph-import-file-action.is-danger{border-color:#fecaca;color:#dc2626;background:#fff7f7}
    .ph-import-error{font-size:13px;font-weight:800;color:#b91c1c}
    .ph-import-helper-banner{display:grid;gap:3px;padding:10px 12px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:12px;line-height:1.35}
    .ph-import-helper-banner strong{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0f766e}
    .ph-import-field-chips{display:flex;flex-wrap:wrap;gap:8px}
    .ph-import-field-chips span{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800}
    .ph-import-flow{margin:0;padding-left:17px;color:#475569;font-size:13px;line-height:1.5}
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
        .ph-import-card{padding:14px}
        .ph-import-dropzone{min-height:116px;padding:12px 14px;border-radius:14px}
        .ph-import-dropzone-icon{width:40px;height:40px;border-radius:14px}
        .ph-import-dropzone-title{font-size:17px}
        .ph-import-selected-file{grid-template-columns:38px minmax(0,1fr);align-items:start}
        .ph-import-selected-icon{width:38px;height:38px}
        .ph-import-file-action{width:100%}
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ph-import-file-input').forEach(function (input) {
        const form = input.closest('.ph-import-upload-form');
        const dropzone = input.closest('.ph-import-dropzone');
        const selectedCard = form ? form.querySelector('.ph-import-selected-file') : null;
        const nameTarget = selectedCard ? selectedCard.querySelector('[data-file-name]') : null;
        const metaTarget = selectedCard ? selectedCard.querySelector('[data-file-meta]') : null;
        const replaceButton = selectedCard ? selectedCard.querySelector('[data-file-replace]') : null;
        const removeButton = selectedCard ? selectedCard.querySelector('[data-file-remove]') : null;

        const formatSize = function (bytes) {
            if (!bytes) return '0 KB';
            const megabytes = bytes / (1024 * 1024);
            return megabytes >= 1
                ? megabytes.toFixed(megabytes >= 10 ? 0 : 1) + ' MB'
                : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        };

        const updateSelectedState = function () {
            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!selectedCard || !dropzone) return;

            if (!file) {
                selectedCard.hidden = true;
                dropzone.hidden = false;
                return;
            }

            if (nameTarget) nameTarget.textContent = file.name;
            if (metaTarget) metaTarget.textContent = formatSize(file.size) + ' | Ready for upload';
            dropzone.hidden = true;
            selectedCard.hidden = false;
        };

        input.addEventListener('change', updateSelectedState);

        if (replaceButton) {
            replaceButton.addEventListener('click', function () {
                input.click();
            });
        }

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                input.value = '';
                updateSelectedState();
            });
        }

        if (dropzone) {
            ['dragenter', 'dragover'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function () {
                    dropzone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function () {
                    dropzone.classList.remove('is-dragover');
                });
            });
        }
    });
});
</script>
