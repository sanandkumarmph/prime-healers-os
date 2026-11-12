<style>
.sw.sw-index{max-width:none;width:100%;min-width:0;margin:0;padding:4px 0 28px;display:grid;gap:12px}
.sw-index .swx-header{margin:0;padding:14px 16px;gap:12px;background:#fff;border:1px solid var(--sw-line);border-radius:14px;align-items:center}
.sw-index .swx-header h1{margin:0 0 3px;font-size:23px;letter-spacing:-.45px}
.sw-index .swx-header p{margin:0;font-size:13px}
.sw-index .swx-header .sw-btn{min-height:40px;padding:8px 14px}
.swx-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.sw-index .swx-metric{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:0;padding:12px 16px;border:1px solid #dde6f2;border-radius:12px;background:#fff;color:var(--sw-ink);text-decoration:none}
.sw-index .swx-metric:hover{border-color:#aebfe1;background:#f8fbff;text-decoration:none}
.swx-metric-label{display:block;font-size:13px;font-weight:700}
.swx-metric small{display:block;margin-top:2px;font-size:11px;color:var(--sw-muted)}
.swx-metric strong{font-size:27px;line-height:1;font-weight:750;color:var(--sw-blue);font-variant-numeric:tabular-nums}
.sw-index .swx-metric-urgent{border-color:#f0d5d8;background:#fffafb}
.swx-metric-urgent strong{color:#af3344}
.swx-workspace{min-width:0;border:1px solid var(--sw-line);border-radius:14px;background:#fff}
.swx-tabs{display:flex;gap:4px;padding:6px 12px 0;border-bottom:1px solid var(--sw-line)}
.sw-index .swx-tabs a{padding:10px 14px;color:var(--sw-muted);font-weight:650;border-bottom:3px solid transparent;text-decoration:none}
.sw-index .swx-tabs a[aria-current=page]{color:var(--sw-blue);border-color:var(--sw-blue);background:#f5f8ff;border-radius:8px 8px 0 0}
.swx-filters{display:grid;grid-template-columns:minmax(180px,1fr) minmax(120px,160px) minmax(130px,170px) auto auto;gap:10px;align-items:end;padding:14px 16px;border-bottom:1px solid var(--sw-line)}
.sw-index .swx-filters input:not([type=checkbox]):not([type=hidden]),.sw-index .swx-filters select{min-height:38px;padding:8px 10px;font-size:13px;border-radius:8px}
.sw-index .swx-filters label{font-size:11px;color:#475a73}
.sw-index .swx-filters .sw-btn{min-height:38px;padding:7px 15px}
.sw-index .swx-mine{display:flex;align-items:center;gap:7px;min-height:38px;white-space:nowrap;margin:0}
.sw-index .swx-mine input[type=checkbox]{margin:0;width:16px;height:16px;min-height:16px;padding:0;flex:0 0 16px;border-radius:4px;appearance:auto}
.sw-index details.swx-advanced{grid-column:1/-1;margin:0;padding:0;border:0}
.sw-index details.swx-advanced>summary{display:inline-flex;align-items:center;gap:7px;margin:0;font-size:12px;color:var(--sw-blue);min-height:28px;cursor:pointer;list-style:revert}
.sw-index details.swx-advanced[open]>summary{margin-bottom:8px}
.swx-filter-count{display:inline-grid;place-items:center;min-width:19px;height:19px;padding:0 5px;border-radius:6px;background:#e8efff;color:#2558c8;font-size:10px}
.swx-advanced-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:12px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px}
.swx-reset{grid-column:1/-1;font-size:12px;justify-self:start}
.swx-list-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 16px}
.swx-list-head>div{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sw-index .swx-list-head h2{margin:0;font-size:15px;font-weight:750}
.swx-result-count{font-size:11px;color:var(--sw-muted);font-variant-numeric:tabular-nums}
.swx-focus{display:flex;gap:4px;flex-wrap:wrap}
.sw-index .swx-focus a{font-size:11px;font-weight:650;padding:5px 9px;color:#5b6d86;border:1px solid #e4eaf3;border-radius:7px;white-space:nowrap;text-decoration:none}
.sw-index .swx-focus a[aria-current=page]{color:#2558c8;background:#edf3ff;border-color:#cbdaf6}
.swx-list-shell{min-width:0;container:swx-list/inline-size}
.swx-table{width:100%;min-width:0;table-layout:fixed;border-collapse:separate;border-spacing:0}
.swx-table th,.swx-table td{padding:11px 12px;text-align:left;vertical-align:top;border-bottom:1px solid #e8edf4;overflow-wrap:anywhere}
.swx-table th{background:#f6f8fb;color:#64748b;font-size:10px;letter-spacing:.055em;text-transform:uppercase;font-weight:750}
.swx-table td{font-size:12px;line-height:1.45}
.swx-table .swx-num{width:4%;text-align:center;color:#7a889d;font-size:12px;font-variant-numeric:tabular-nums}
.swx-contact{width:29%}.swx-owner{width:17%}.swx-created{width:16%}.swx-stage{width:14%}.swx-next{width:20%}
.swx-table tbody tr:last-child td{border-bottom:0}
.swx-record:hover td{background:#fbfdff}
.sw-index .swx-subject{display:block;font-size:13px;font-weight:750;color:#213c64;line-height:1.4}
.swx-contact-meta{display:flex;flex-wrap:wrap;gap:3px 10px;margin-top:5px;color:#63748b;font-size:12px}
.sw-index .swx-contact-meta a{color:#63748b}
.swx-owner>strong,.swx-created strong{display:block;font-size:12px;font-weight:650}
.swx-secondary{display:block;margin-top:4px;color:#708096;font-size:11px}
.swx-due{display:block;margin-bottom:5px;color:#63748b;font-size:11px;font-variant-numeric:tabular-nums}
.swx-due.is-overdue{color:#a82f42;font-weight:650}
.sw-index .swx-row-action{padding:5px 9px;min-height:32px;font-size:11px;gap:8px;background:#f8faff;border-color:#dce5f3;color:#2c538a;text-align:left;justify-content:space-between;width:100%}
.sw-index .swx-row-action:hover{background:#edf3ff}
.swx-table .sw-badge{font-size:10px;padding:4px 7px;white-space:normal;line-height:1.35}
.swx-table .sw-badge[data-status=quoted]{background:#fff4de;color:#8e5a15}
.swx-table .sw-badge[data-status=accepted]{background:#f0ebff;color:#6241a4}
.swx-attention-order{display:grid;gap:4px;align-content:center;min-height:38px;color:#53677f;font-size:11px}.swx-attention-order strong{font-size:12px}
.swx-attention-guide{margin:0;padding:0 16px 12px;font-size:12px;color:#63748b}.swx-attention-table{width:100%;table-layout:fixed;border-collapse:collapse}.swx-attention-table th,.swx-attention-table td{text-align:left;vertical-align:top;padding:12px;border-bottom:1px solid #e8edf4;overflow-wrap:anywhere}.swx-attention-table th{background:#f6f8fb;color:#63748b;font-size:10px;letter-spacing:.04em;text-transform:uppercase}.swx-attention-table th:nth-child(1){width:4%}.swx-attention-table th:nth-child(2){width:28%}.swx-attention-table th:nth-child(3){width:16%}.swx-attention-table th:nth-child(4){width:30%}.swx-attention-table th:nth-child(5){width:22%}.swx-attention-table td{font-size:12px}.swx-attention-table td>small,.swx-attention-table td>span{display:block;margin-top:5px;color:#63748b}.swx-attention-contact>a{display:block;font-size:13px;font-weight:750;color:#213c64}.swx-attention-contact small,.swx-attention-owner small,.swx-attention-reason small{font-size:11px}.swx-attention-table .swx-attention-number{color:#7a889d;font-size:12px;text-align:center}.swx-attention-reason p{font-size:12px;margin:0;line-height:1.5}.swx-attention-reason small{display:block;margin-top:5px;color:#63748b}.swx-attention-reason small.is-overdue{color:#ad3346;font-weight:650}.swx-attention-action .sw-btn{font-size:12px;min-height:38px;width:100%;justify-content:space-between;text-align:left;background:#f5f8ff;color:#2558c8;border-color:#d4e0f5}.swx-attention-table tr[data-attention-key=ready_order] .swx-attention-action .sw-btn{background:#2558c8;color:white;border-color:#2558c8}
@media(max-width:1000px){.swx-attention-table,.swx-attention-table tbody{display:block}.swx-attention-table thead{display:none}.swx-attention-table tbody{padding:0 12px 12px;display:grid;gap:9px}.swx-attention-table tr[data-attention-row]{display:grid;grid-template-columns:22px minmax(0,1fr);gap:8px 10px;border:1px solid #dce5ef;border-radius:10px;padding:12px}.swx-attention-table tr[data-attention-row] td{width:auto;min-width:0;border:0;padding:0}.swx-attention-number{grid-row:1/5}.swx-attention-contact,.swx-attention-owner,.swx-attention-reason,.swx-attention-action{grid-column:2}.swx-attention-owner{display:flex;gap:7px;flex-wrap:wrap}.swx-attention-table .swx-attention-owner>small{display:inline;margin:0}.swx-attention-action{border-top:1px solid #e8edf4!important;padding-top:9px!important}.swx-attention-action .sw-btn{width:auto;min-height:42px;max-width:100%}.swx-attention-reason p{font-size:13px}}
.swx-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:11px 16px;border-top:1px solid var(--sw-line);font-size:11px;color:var(--sw-muted)}
.swx-pagination>div{min-width:0;max-width:100%}
.sw-index .swx-empty{padding:30px 16px;text-align:center;color:var(--sw-muted)}
.sw-index .swx-empty h3{margin-bottom:5px;color:var(--sw-ink)}
@container swx-list (max-width:850px){
    .swx-table,.swx-table tbody{display:block}.swx-table thead{display:none}
    .swx-table tbody{padding:0 12px 12px;display:grid;gap:8px}
    .swx-table .swx-record{display:grid;grid-template-columns:28px minmax(0,1.5fr) minmax(0,1fr);gap:10px 12px;padding:12px;border:1px solid #e0e7ef;border-radius:10px;background:#fff}
    .swx-table .swx-record td{width:auto;min-width:0;padding:0;border:0;background:transparent}
    .swx-record .swx-num{grid-column:1;grid-row:1/4;text-align:left;padding-top:1px!important}
    .swx-record .swx-contact{grid-column:2;grid-row:1}.swx-record .swx-stage{grid-column:3;grid-row:1}
    .swx-record .swx-owner{grid-column:2;grid-row:2}.swx-record .swx-created{grid-column:3;grid-row:2}
    .swx-record .swx-next{grid-column:2/-1;grid-row:3}
    .swx-record :is(.swx-owner,.swx-created)::before{display:block;content:attr(data-label);font-size:9px;font-weight:750;letter-spacing:.04em;text-transform:uppercase;color:#7c8a9c;margin-bottom:3px}
    .swx-record .swx-next{display:flex;align-items:center;justify-content:space-between;gap:8px;border-top:1px solid #edf1f6!important;padding-top:8px!important}
    .swx-record .swx-next .swx-row-action{width:auto;margin-left:auto}
    .swx-record .swx-due{margin:0}
}
@container swx-list (max-width:460px){
    .swx-table .swx-record{grid-template-columns:22px repeat(2,minmax(0,1fr));gap:8px;padding:10px}
    .swx-record .swx-num{grid-row:1/5}.swx-record .swx-contact{grid-column:2/-1}
    .swx-record .swx-stage{grid-column:2/-1;grid-row:2}
    .swx-record .swx-owner{grid-row:3}.swx-record .swx-created{grid-row:3}
    .swx-record .swx-next{grid-row:4;flex-wrap:wrap}.swx-record .swx-next .swx-row-action{min-height:36px}
}
@media(max-width:1100px){.swx-filters{grid-template-columns:minmax(160px,1fr) repeat(2,minmax(120px,1fr))}.swx-search{grid-column:1/-1}.swx-mine{grid-column:1/3}.swx-apply{grid-column:3}}
@media(max-width:600px){
    .sw.sw-index{gap:9px;padding:4px 0 100px}.sw-index .swx-header{flex-direction:row;align-items:center;padding:12px;gap:8px}
    .sw-index .swx-header h1{font-size:19px}.sw-index .swx-header p{display:none}.sw-index .swx-header .sw-btn{font-size:12px;padding:7px 10px}
    .swx-metrics{gap:6px}.sw-index .swx-metric{display:grid;gap:6px;padding:10px 8px;align-content:start}
    .swx-metric-label{font-size:10px;line-height:1.35}.swx-metric small{display:none}.swx-metric strong{font-size:24px}
    .swx-tabs{gap:0;padding:3px 6px 0;flex-wrap:wrap}.sw-index .swx-tabs a{padding:10px;font-size:12px;flex:1 1 40%;text-align:center}
    .swx-filters{padding:12px;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr))}.swx-search{grid-column:1/-1}
    .swx-mine{grid-column:1}.swx-apply{grid-column:2}.swx-advanced-fields{grid-template-columns:repeat(2,minmax(0,1fr));padding:9px}
    .sw-index .swx-filters input:not([type=checkbox]):not([type=hidden]),.sw-index .swx-filters select{font-size:16px;min-height:40px}
    .swx-list-head{padding:11px 12px;gap:8px}.sw-index .swx-list-head h2{font-size:14px}.swx-pagination{padding:10px 12px}
}
</style>
