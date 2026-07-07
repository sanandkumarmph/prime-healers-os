@extends('layouts.app')

@section('content')
@php
    use App\Models\FollowUp;
    use Illuminate\Support\Str;

    $tabs = [
        'all' => 'All',
        'unread' => 'Unread',
        'assigned_me' => 'Assigned to Me',
        'staff_messages' => 'Staff Messages',
        'system_alerts' => 'System Alerts',
        'payments' => 'Payment Follow-ups',
        'delivery' => 'Delivery / Service',
        'completed' => 'Completed',
    ];

    $statusTone = function (string $status): string {
        return match ($status) {
            FollowUp::STATUS_OVERDUE => 'background:#fee2e2;color:#b91c1c;border-color:#fecaca;',
            FollowUp::STATUS_COMPLETED => 'background:#dcfce7;color:#166534;border-color:#86efac;',
            FollowUp::STATUS_CANCELLED => 'background:#e2e8f0;color:#334155;border-color:#cbd5e1;',
            default => 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
        };
    };

    $priorityTone = function (?string $priority): string {
        return match ($priority) {
            FollowUp::PRIORITY_URGENT => 'background:#7f1d1d;color:#fff;',
            FollowUp::PRIORITY_HIGH => 'background:#fee2e2;color:#b91c1c;',
            FollowUp::PRIORITY_MEDIUM => 'background:#fef3c7;color:#b45309;',
            default => 'background:#ecfeff;color:#0f766e;',
        };
    };

    $callHref = fn (?string $phone) => $phone ? 'tel:' . preg_replace('/\D+/', '', $phone) : null;
    $canCreatePayments = auth()->user()?->canAccessModule('payments', 'create') ?? false;
    $paymentRecordHref = function (FollowUp $followUp): ?string {
        if ($followUp->invoice_id) {
            return route('invoices.show', $followUp->invoice_id) . '#record-payment';
        }

        if ($followUp->rental_id) {
            return route('payments.create', $followUp->rental_id);
        }

        if ($followUp->sale_id) {
            return route('sales.show', $followUp->sale_id) . '#record-payment';
        }

        if ($followUp->delivery?->rental_id) {
            return route('payments.create', $followUp->delivery->rental_id);
        }

        if ($followUp->delivery?->sale_id) {
            return route('sales.show', $followUp->delivery->sale_id) . '#record-payment';
        }

        return null;
    };
    $paymentDueLabel = function (FollowUp $followUp): ?string {
        $invoice = $followUp->invoice;
        $amount = $invoice ? (float) ($invoice->balance_amount ?? $invoice->total_amount ?? 0) : null;

        if ($amount === null || $amount <= 0) {
            return null;
        }

        return 'Due: Rs. ' . number_format($amount, 2);
    };
    $tabsWithCounts = collect($tabs)->map(function ($label, $key) use ($counts) {
        return ['key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
    });
    $clearHref = route('communication-center.index', ['tab' => $tab]);
@endphp

<style>
    .comms-page { display:grid; gap:18px; padding:22px 0 28px; }
    .comms-shell,
    .comms-card {
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:#fff;
        box-shadow:0 16px 38px rgba(15,23,42,.05);
    }
    .comms-shell { padding:18px; display:grid; gap:16px; }
    .comms-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .comms-head h1 { margin:0; font-size:28px; color:#0f172a; }
    .comms-head p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .comms-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .comms-tab-row { display:flex; gap:10px; flex-wrap:wrap; }
    .comms-tab {
        display:inline-flex; align-items:center; gap:8px; min-height:40px; padding:8px 14px;
        border-radius:999px; border:1px solid #dbe3ef; background:#f8fafc; color:#334155;
        text-decoration:none; font-size:12px; font-weight:800;
    }
    .comms-tab.is-active { background:#0f172a; border-color:#0f172a; color:#fff; }
    .comms-tab-count {
        display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px;
        padding:0 6px; border-radius:999px; background:rgba(255,255,255,.18); color:inherit; font-size:11px;
    }
    .comms-search-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; align-items:end; }
    .comms-input,
    .comms-select,
    .comms-textarea {
        width:100%; min-height:44px; box-sizing:border-box; border:1px solid #dbe3ef; border-radius:14px;
        background:#fff; color:#0f172a; padding:10px 12px; font-size:14px;
    }
    .comms-textarea { min-height:96px; resize:vertical; }
    .comms-btn, .comms-btn-soft, .comms-btn-ghost {
        display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px;
        padding:10px 14px; border-radius:14px; text-decoration:none; border:1px solid transparent;
        font-size:13px; font-weight:800; cursor:pointer; font-family:inherit;
    }
    .comms-btn { background:#2563eb; color:#fff; }
    .comms-btn-soft { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .comms-btn-ghost { background:#fff; color:#334155; border-color:#dbe3ef; }
    .comms-active-filters { display:flex; gap:8px; flex-wrap:wrap; }
    .comms-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        background:#f8fafc; border:1px solid #dbe3ef; color:#475569; font-size:12px; font-weight:700;
    }
    .comms-list { display:grid; gap:14px; }
    .comms-card { padding:16px; display:grid; gap:14px; }
    .comms-card-top { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:16px; align-items:start; }
    .comms-card-main { display:grid; gap:8px; min-width:0; }
    .comms-card-headline { display:flex; flex-wrap:wrap; gap:8px; align-items:center; min-width:0; }
    .comms-card-main h2 { margin:0; font-size:18px; color:#0f172a; }
    .comms-card-line { color:#475569; font-size:13px; min-width:0; overflow-wrap:anywhere; }
    .comms-card-subtle { color:#64748b; font-size:12px; line-height:1.55; overflow-wrap:anywhere; }
    .comms-status-chip, .comms-priority-chip {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        border:1px solid transparent; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .comms-priority-chip { border:none; }
    .comms-card-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:flex-start; }
    .comms-card-contacts { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
    .comms-contact-block,
    .comms-kv-item {
        display:grid; gap:6px; min-width:0; padding:12px 14px; border-radius:16px; background:#f8fafc; border:1px solid #e5edf7;
    }
    .comms-contact-label,
    .comms-kv-item span {
        font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#64748b;
    }
    .comms-contact-value,
    .comms-kv-item strong {
        font-size:13px; color:#0f172a; font-weight:700; line-height:1.55; overflow-wrap:anywhere;
    }
    .comms-contact-link {
        display:inline-flex; align-items:center; gap:6px; color:#2563eb; font-size:12px; font-weight:800; text-decoration:none; width:max-content; max-width:100%;
    }
    .comms-kv { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; }
    .comms-more { position:relative; }
    .comms-more summary { list-style:none; }
    .comms-more summary::-webkit-details-marker { display:none; }
    .comms-more-panel {
        position:absolute; right:0; top:calc(100% + 8px); min-width:220px; max-width:min(280px, calc(100vw - 48px));
        padding:10px; border-radius:16px; border:1px solid #dbe3ef; background:#fff; box-shadow:0 18px 40px rgba(15,23,42,.12);
        display:grid; gap:8px; z-index:30;
    }
    .comms-more-panel a,
    .comms-more-panel button,
    .comms-more-panel form button {
        width:100%; display:inline-flex; align-items:center; justify-content:flex-start; min-height:38px;
        padding:9px 11px; border-radius:12px; border:1px solid #e5edf7; background:#fff; color:#334155;
        font-size:12px; font-weight:800; text-decoration:none; cursor:pointer; box-sizing:border-box; font-family:inherit;
    }
    .comms-more-panel form { margin:0; }
    .comms-empty { padding:28px 18px; text-align:center; color:#64748b; }
    .comms-empty strong { display:block; color:#0f172a; font-size:18px; margin-bottom:6px; }
    .comms-note-strip {
        display:grid; gap:6px; padding:12px 14px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa;
    }
    .comms-note-strip span { font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#9a3412; }
    .comms-modal[hidden] { display:none; }
    .comms-modal {
        position:fixed; inset:0; z-index:1300; background:rgba(15,23,42,.45); display:flex; align-items:center; justify-content:center; padding:18px;
    }
    .comms-modal-dialog {
        width:min(100%, 560px); max-height:calc(100vh - 36px); overflow:auto; border-radius:20px; background:#fff; border:1px solid #dbe3ef; box-shadow:0 28px 60px rgba(15,23,42,.24);
    }
    .comms-modal-head, .comms-modal-foot { position:sticky; background:#fff; z-index:2; }
    .comms-modal-head { top:0; padding:18px 18px 14px; border-bottom:1px solid #eef2f7; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .comms-modal-head h3 { margin:0; font-size:18px; color:#0f172a; }
    .comms-modal-head p { margin:6px 0 0; color:#64748b; font-size:12px; }
    .comms-modal-body { padding:18px; display:grid; gap:14px; }
    .comms-modal-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
    .comms-modal-field { display:grid; gap:6px; }
    .comms-modal-field label { font-size:12px; font-weight:800; color:#334155; }
    .comms-modal-foot { bottom:0; padding:14px 18px 18px; border-top:1px solid #eef2f7; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .comms-close {
        display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:999px; border:1px solid #dbe3ef; background:#fff; color:#475569; cursor:pointer;
    }
    .comms-inbox-toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .comms-filter-panel { border:1px solid #dbe3ef; border-radius:16px; background:#f8fbff; overflow:hidden; }
    .comms-filter-panel summary { list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; font-weight:900; color:#0f172a; }
    .comms-filter-panel summary::-webkit-details-marker { display:none; }
    .comms-filter-panel:not([open]) { background:#fff; }
    .comms-filter-panel-body { display:grid; gap:10px; padding:0 12px 12px; }
    .comms-list { gap:8px; }
    .comms-inbox-item {
        border:1px solid #dbe3ef; border-left:4px solid #cbd5e1; border-radius:12px; background:#fff;
        box-shadow:0 8px 22px rgba(15,23,42,.04); overflow:visible;
    }
    .comms-inbox-item.is-unread { border-left-color:#2563eb; background:#f8fbff; }
    .comms-inbox-item.is-read { border-left-color:#e2e8f0; background:#fff; }
    .comms-inbox-item[open] { box-shadow:0 16px 34px rgba(15,23,42,.08); }
    .comms-inbox-item > summary { list-style:none; cursor:pointer; padding:6px 9px; }
    .comms-inbox-item > summary::-webkit-details-marker { display:none; }
    .comms-row {
        display:grid; grid-template-columns:34px 10px minmax(84px,.45fr) minmax(220px,1.4fr) minmax(132px,.65fr) minmax(120px,.6fr) minmax(126px,.62fr);
        gap:7px; align-items:center; min-width:0;
    }
    .comms-serial {
        color:#64748b; font-size:11px; font-weight:900; text-align:right; font-variant-numeric:tabular-nums;
    }
    .comms-unread-dot { width:8px; height:8px; border-radius:999px; background:#2563eb; opacity:0; }
    .is-unread .comms-unread-dot { opacity:1; }
    .comms-row-title { display:grid; gap:1px; min-width:0; }
    .comms-row-title strong { color:#0f172a; font-size:12px; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .is-unread .comms-row-title strong { font-weight:900; }
    .comms-row-title span, .comms-row-meta, .comms-preview { color:#64748b; font-size:10.5px; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .comms-preview { color:#475569; }
    .comms-row-badges { display:flex; gap:4px; flex-wrap:wrap; justify-content:flex-end; }
    .comms-type-chip {
        display:inline-flex; align-items:center; width:max-content; max-width:100%; padding:3px 7px; border-radius:999px;
        background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; font-size:9.5px; font-weight:900; text-transform:uppercase; letter-spacing:.03em;
    }
    .comms-type-chip.is-system { background:#f1f5f9; color:#334155; border-color:#cbd5e1; }
    .comms-type-chip.is-payment { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
    .comms-type-chip.is-delivery { background:#ecfdf5; color:#166534; border-color:#bbf7d0; }
    .comms-detail-panel { display:grid; gap:6px; padding:0 8px 8px 58px; border-top:1px solid #eef2f7; }
    .comms-detail-actions { display:flex; gap:5px; flex-wrap:wrap; align-items:center; justify-content:flex-start; padding-top:7px; }
    .comms-detail-actions .comms-btn,
    .comms-detail-actions .comms-btn-soft,
    .comms-detail-actions .comms-btn-ghost { min-height:28px; padding:5px 9px; border-radius:9px; font-size:10.5px; }
    .comms-detail-grid { display:grid; grid-template-columns:minmax(0, 1fr) 420px; gap:6px; }
    .comms-thread-box { display:grid; gap:3px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:9px; background:#fff; align-content:start; }
    .comms-thread-box h3 { margin:0; color:#0f172a; font-size:11px; font-weight:800; }
    .comms-thread-box p { margin:0; color:#475569; font-size:10.5px; line-height:1.3; overflow-wrap:anywhere; }
    .comms-card-contacts, .comms-kv { gap:5px; }
    .comms-card-contacts { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .comms-kv { grid-template-columns:repeat(4, minmax(0,1fr)); }
    .comms-contact-block, .comms-kv-item { padding:6px 7px; border-radius:9px; gap:2px; min-height:0; }
    .comms-contact-label, .comms-kv-item span { font-size:9.5px; letter-spacing:.04em; }
    .comms-contact-value, .comms-kv-item strong { font-size:11px; line-height:1.25; overflow-wrap:anywhere; }
    .comms-status-chip, .comms-priority-chip { padding:3px 7px; font-size:9.5px; letter-spacing:.03em; }
    .comms-note-strip { gap:5px; padding:7px 8px; border-radius:9px; }
    .comms-note-strip span { font-size:9.5px; letter-spacing:.04em; }
    .comms-note-strip .comms-input,
    .comms-note-strip .comms-select { min-height:30px; border-radius:8px; padding:5px 8px; font-size:11.5px; }
    .comms-note-strip .comms-modal-grid { gap:8px; }
    .comms-note-strip .comms-modal-field { gap:3px; }
    .comms-note-strip .comms-modal-field label { font-size:10.5px; }
    .comms-note-strip .comms-btn-ghost { min-height:30px; padding:5px 10px; border-radius:9px; font-size:11px; }
    .comms-reschedule-form { display:grid; grid-template-columns:minmax(160px, .9fr) minmax(160px, .9fr) minmax(110px, .55fr) minmax(180px, 1fr) auto; gap:8px; align-items:end; margin-top:3px; }
    @media (max-width: 960px) {
        .comms-search-row { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-card-top { grid-template-columns:1fr; }
        .comms-card-actions { justify-content:flex-start; }
        .comms-card-contacts { grid-template-columns:1fr; }
        .comms-kv { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-row { grid-template-columns:28px 10px minmax(78px,.45fr) minmax(0,1fr); }
        .comms-row-meta.optional, .comms-preview, .comms-row-badges { grid-column:2 / -1; justify-content:flex-start; }
        .comms-detail-grid { grid-template-columns:1fr; }
        .comms-kv { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-reschedule-form { grid-template-columns:repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 640px) {
        .comms-page { gap:12px; padding:10px 0 20px; }
        .comms-shell, .comms-card { border-radius:18px; }
        .comms-shell { padding:14px; }
        .comms-head h1 { font-size:24px; }
        .comms-search-row, .comms-modal-grid, .comms-kv { grid-template-columns:1fr; }
        .comms-card-actions { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .comms-card-actions > * { width:100%; }
        .comms-more { width:100%; }
        .comms-more-panel { position:static; min-width:0; max-width:none; box-shadow:none; margin-top:8px; }
        .comms-modal { padding:0; align-items:stretch; }
        .comms-modal-dialog { width:100%; max-height:100vh; border-radius:0; }
        .comms-row { grid-template-columns:26px 10px minmax(0,1fr); gap:6px; }
        .comms-row > *:not(.comms-serial):not(.comms-unread-dot) { grid-column:3; }
        .comms-detail-panel { padding:0 8px 8px 36px; }
        .comms-detail-actions { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-detail-actions > * { width:100%; }
        .comms-reschedule-form { grid-template-columns:1fr; }
    }

    /* Communication Center compact action-first refinement */
    .comms-page { gap:12px; padding:12px 0 22px; }
    .comms-shell { padding:12px 14px; gap:10px; border-radius:16px; }
    .comms-head { align-items:center; gap:12px; }
    .comms-head h1 { font-size:26px; line-height:1.1; }
    .comms-head p { margin-top:3px; max-width:620px; font-size:12px; line-height:1.35; }
    .comms-actions { gap:8px; }
    .comms-actions .comms-btn,
    .comms-actions .comms-btn-ghost { min-height:36px; padding:8px 12px; border-radius:11px; font-size:12px; }
    .comms-tab-row { gap:6px; }
    .comms-tab { min-height:32px; padding:6px 10px; font-size:11px; box-shadow:0 6px 14px rgba(15,23,42,.03); }
    .comms-tab.is-active { background:#0f172a; border-color:#0f172a; box-shadow:0 10px 22px rgba(15,23,42,.18); }
    .comms-tab-count { min-width:18px; height:18px; padding:0 5px; font-size:10px; }
    .comms-filter-panel { border-radius:13px; background:#fff; }
    .comms-filter-panel summary { min-height:38px; padding:8px 10px; font-size:12px; }
    .comms-filter-panel-body { padding:0 10px 10px; }
    .comms-search-row { grid-template-columns:minmax(240px, 1.35fr) repeat(3, minmax(130px, .65fr)) auto; gap:8px; }
    .comms-input, .comms-select { min-height:38px; border-radius:11px; padding:8px 10px; font-size:13px; }
    .comms-inbox-item { border-radius:13px; }
    .comms-inbox-item > summary { padding:8px 10px; }
    .comms-row {
        grid-template-columns:26px 8px minmax(78px,.42fr) minmax(210px,1.25fr) minmax(112px,.55fr) minmax(112px,.6fr) minmax(150px,.72fr);
        gap:8px;
    }
    .comms-row-title strong { font-size:13px; }
    .comms-row-title span, .comms-row-meta, .comms-preview { font-size:11px; }
    .comms-status-chip, .comms-priority-chip { padding:3px 7px; font-size:9.5px; }
    .comms-detail-panel { gap:8px; padding:0 10px 10px 46px; }
    .comms-detail-actions { gap:7px; padding-top:9px; }
    .comms-detail-actions .comms-btn,
    .comms-detail-actions .comms-btn-soft,
    .comms-detail-actions .comms-btn-ghost,
    .comms-detail-actions .comms-payment-cta { min-height:34px; padding:7px 11px; border-radius:10px; font-size:11.5px; }
    .comms-payment-cta {
        display:inline-flex; align-items:center; justify-content:center; gap:7px;
        border:1px solid #111827; background:#0f172a; color:#fff; text-decoration:none;
        font-weight:900; box-shadow:0 8px 18px rgba(15,23,42,.18);
    }
    .comms-payment-cta::before { content:"Rs."; display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:10px; font-weight:900; }
    .comms-payment-meta { display:inline-flex; align-items:center; padding:6px 9px; border-radius:999px; background:#fff1f2; color:#be123c; font-size:11px; font-weight:900; }
    .comms-detail-grid { grid-template-columns:minmax(0, 1fr) minmax(260px, 360px); }
    .comms-card-contacts { grid-template-columns:repeat(3, minmax(0,1fr)); }
    .comms-thread-box, .comms-contact-block, .comms-kv-item { border-radius:10px; }
    @media (max-width: 960px) {
        .comms-search-row { grid-template-columns:repeat(2, minmax(0,1fr)); }
        .comms-row { grid-template-columns:24px 8px minmax(76px,.45fr) minmax(0,1fr); }
        .comms-detail-panel { padding-left:34px; }
        .comms-detail-grid, .comms-card-contacts { grid-template-columns:1fr; }
    }
    @media (max-width: 640px) {
        .comms-page { padding:8px 0 92px; overflow-x:hidden; }
        .comms-shell { padding:12px; gap:9px; }
        .comms-head { align-items:flex-start; }
        .comms-head h1 { font-size:22px; }
        .comms-head p { font-size:12px; }
        .comms-actions { display:grid; grid-template-columns:1fr 1fr; width:100%; }
        .comms-actions .comms-btn { grid-column:1 / -1; }
        .comms-tab-row { flex-wrap:nowrap; overflow-x:auto; padding-bottom:3px; margin-inline:-2px; scroll-snap-type:x proximity; }
        .comms-tab { flex:0 0 auto; scroll-snap-align:start; }
        .comms-filter-panel summary { font-size:12px; }
        .comms-search-row { grid-template-columns:1fr; }
        .comms-row { grid-template-columns:22px 8px minmax(0,1fr); align-items:start; }
        .comms-type-chip, .comms-row-title, .comms-preview, .comms-row-badges { grid-column:3; }
        .comms-row-meta.optional { display:none; }
        .comms-row-title strong { white-space:normal; }
        .comms-row-title span, .comms-preview { white-space:normal; overflow:visible; }
        .comms-detail-panel { padding:0 8px 10px 32px; }
        .comms-detail-actions { grid-template-columns:1fr 1fr; }
        .comms-detail-actions .comms-payment-cta { grid-column:1 / -1; }
    }
</style>

<div class="container comms-page">
    <section class="comms-shell">
        <div class="comms-head">
            <div>
                <h1>Communication Center</h1>
                <p>Follow-ups, alerts, payment outreach, and service messages in one action queue.</p>
            </div>
            <div class="comms-actions">
                @if($featureReady)
                    <a href="{{ route('communication-center.index', array_merge(request()->except('page'), ['tab' => 'unread'])) }}" class="comms-btn-ghost">Show unread only</a>
                    <button type="button" class="comms-btn-ghost" data-comms-mark-all-read>Mark all as read</button>
                    <button type="button"
                            class="comms-btn"
                            data-open-follow-up-modal
                            data-follow-up-context='{}'
                            data-follow-up-type="{{ \App\Models\FollowUp::TYPE_GENERAL }}"
                            data-follow-up-priority="{{ \App\Models\FollowUp::PRIORITY_MEDIUM }}">
                        Add Follow-up
                    </button>
                @endif
            </div>
        </div>

        @if(!$featureReady)
            <div class="comms-note-strip">
                <span>Setup Pending</span>
                <div>The Communication Center will be available after the <code>follow_ups</code> migration is applied.</div>
            </div>
        @else
            <div class="comms-tab-row">
                @foreach($tabsWithCounts as $tabItem)
                    <a href="{{ route('communication-center.index', array_merge(request()->except('page'), ['tab' => $tabItem['key']])) }}"
                       class="comms-tab {{ $tab === $tabItem['key'] ? 'is-active' : '' }}">
                        <span>{{ $tabItem['label'] }}</span>
                        <span class="comms-tab-count">{{ number_format($tabItem['count']) }}</span>
                    </a>
                @endforeach
            </div>

            <details class="comms-filter-panel">
                <summary>
                    <span>Search &amp; Filters</span>
                    <span class="comms-card-subtle">{{ !empty($activeFilters) ? count($activeFilters) . ' active' : 'Closed' }}</span>
                </summary>
                <div class="comms-filter-panel-body">
                    <form method="GET" action="{{ route('communication-center.index') }}" class="comms-search-row">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <input class="comms-input" type="search" name="search" value="{{ request('search') }}" placeholder="Search customer, partner, actual client, phone, rental, invoice, product">
                        <select class="comms-select" name="priority">
                            <option value="">All priorities</option>
                            @foreach(\App\Models\FollowUp::PRIORITIES as $value => $label)
                                <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select class="comms-select" name="type">
                            <option value="">All types</option>
                            @foreach(\App\Models\FollowUp::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select class="comms-select" name="staff">
                            <option value="">All staff</option>
                            <option value="me" @selected(request('staff') === 'me')>My Work</option>
                            <option value="unassigned" @selected(request('staff') === 'unassigned')>Unassigned</option>
                            @foreach($assignableUsers as $user)
                                <option value="user:{{ $user->id }}" @selected(request('staff') === 'user:' . $user->id)>{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                            @endforeach
                        </select>
                        <select class="comms-select" name="sort">
                            <option value="unread_first" @selected(request('sort', 'unread_first') === 'unread_first')>Unread first</option>
                            <option value="overdue_first" @selected(request('sort') === 'overdue_first')>Overdue first</option>
                            <option value="high_priority" @selected(request('sort') === 'high_priority')>High priority first</option>
                            <option value="latest" @selected(request('sort') === 'latest')>Latest first</option>
                        </select>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="comms-btn" type="submit">Apply</button>
                            <a href="{{ $clearHref }}" class="comms-btn-ghost">Clear</a>
                        </div>
                    </form>
                </div>
            </details>

            @if(!empty($activeFilters))
                <div class="comms-active-filters" aria-label="Active communication filters">
                    @foreach($activeFilters as $chip)
                        <span class="comms-chip">{{ $chip }}</span>
                    @endforeach
                </div>
            @endif
        @endif
    </section>

    @if($featureReady)
        <section class="comms-list">
            @forelse($followUps as $followUp)
                @php
                    $effectiveStatus = $followUp->effectiveStatus();
                    $callTargetPhone = $followUp->callTargetPhone();
                    $callTargetHref = $callHref($callTargetPhone);
                    $referenceHref = $followUp->delivery_id
                        ? route('deliveries.show', $followUp->delivery_id)
                        : ($followUp->rental_id
                            ? route('rentals.show', $followUp->rental_id)
                            : ($followUp->sale_id
                                ? route('sales.show', $followUp->sale_id)
                                : ($followUp->invoice_id ? route('invoices.show', $followUp->invoice_id) : null)));
                    $noteContext = [
                        'customer_id' => $followUp->customer_id,
                        'business_partner_id' => $followUp->business_partner_id,
                        'partner_client_id' => $followUp->partner_client_id,
                        'rental_id' => $followUp->rental_id,
                        'sale_id' => $followUp->sale_id,
                        'invoice_id' => $followUp->invoice_id,
                        'delivery_id' => $followUp->delivery_id,
                        'reference' => $followUp->referenceLabel(),
                        'reminder_contact' => collect([$followUp->reminderContactName(), $followUp->reminderContactPhone()])->filter()->implode(' | '),
                        'service_contact' => collect([$followUp->serviceContactName(), $followUp->serviceContactPhone()])->filter()->implode(' | '),
                        'service_address' => $followUp->serviceContactAddress(),
                    ];
                    $lastCommunication = $followUp->note ?: 'No communication note added yet.';
                    $isClosed = in_array($followUp->status, [\App\Models\FollowUp::STATUS_COMPLETED, \App\Models\FollowUp::STATUS_CANCELLED], true);
                    $isUnread = !$isClosed;
                    $sourceName = $followUp->is_system_generated ? 'System' : ($followUp->createdByUser?->name ?? 'Staff');
                    $senderLabel = $followUp->is_system_generated ? 'System' : 'From ' . $sourceName;
                    $isPaymentAlert = $followUp->followup_type === \App\Models\FollowUp::TYPE_PAYMENT
                        || Str::contains(Str::lower((string) $followUp->title), ['payment', 'invoice', 'balance due', 'overdue']);
                    $recordPaymentHref = $isPaymentAlert ? $paymentRecordHref($followUp) : null;
                    $paymentDueText = $isPaymentAlert ? $paymentDueLabel($followUp) : null;
                    $typeClass = match ($followUp->followup_type) {
                        \App\Models\FollowUp::TYPE_PAYMENT => 'is-payment',
                        \App\Models\FollowUp::TYPE_DELIVERY, \App\Models\FollowUp::TYPE_SERVICE, \App\Models\FollowUp::TYPE_PICKUP => 'is-delivery',
                        default => $followUp->is_system_generated ? 'is-system' : '',
                    };
                    $typeBadge = match ($followUp->followup_type) {
                        \App\Models\FollowUp::TYPE_PAYMENT => 'Payment',
                        \App\Models\FollowUp::TYPE_DELIVERY => 'Delivery',
                        \App\Models\FollowUp::TYPE_SERVICE => 'Service',
                        \App\Models\FollowUp::TYPE_PICKUP => 'Pickup',
                        \App\Models\FollowUp::TYPE_GENERAL, \App\Models\FollowUp::TYPE_CALLBACK, \App\Models\FollowUp::TYPE_COMPLAINT, \App\Models\FollowUp::TYPE_ESCALATION => $followUp->is_system_generated ? 'System' : 'Staff',
                        default => $followUp->typeLabel(),
                    };
                    $dueState = $isClosed
                        ? 'Completed'
                        : ($followUp->due_at && $followUp->due_at->isPast()
                            ? 'Overdue ' . $followUp->due_at->diffForHumans(null, true)
                            : ($followUp->due_at && $followUp->due_at->isToday() ? 'Due today' : 'Scheduled'));
                    $preview = Str::limit(preg_replace('/\s+/', ' ', trim($lastCommunication)), 120);
                @endphp
                <details class="comms-inbox-item {{ $isUnread ? 'is-unread' : 'is-read' }}" data-comms-id="{{ $followUp->id }}">
                    <summary>
                        <div class="comms-row">
                            <span class="comms-serial">{{ $followUps instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $followUps->firstItem() + $loop->index : $loop->iteration }}</span>
                            <span class="comms-unread-dot" aria-hidden="true"></span>
                            <span class="comms-type-chip {{ $typeClass }}">{{ $typeBadge }}</span>
                            <span class="comms-row-title">
                                <strong>{{ $followUp->title }}</strong>
                                <span>{{ $followUp->referenceLabel() }}@if($followUp->productLabel()) / {{ $followUp->productLabel() }}@endif</span>
                            </span>
                            <span class="comms-row-meta optional">{{ $senderLabel }} / Assigned: {{ $followUp->assignedUser?->name ?? 'Unassigned' }}</span>
                            <span class="comms-preview">{{ $preview }}</span>
                            <span class="comms-row-badges">
                                <span class="comms-status-chip" style="{{ $statusTone($effectiveStatus) }}">{{ $dueState }}</span>
                                <span class="comms-priority-chip" style="{{ $priorityTone($followUp->priority) }}">{{ $followUp->priorityLabel() }}</span>
                            </span>
                        </div>
                    </summary>

                    <div class="comms-detail-panel">
                        <div class="comms-detail-actions">
                            @if($canCreatePayments && $recordPaymentHref)
                                <a href="{{ $recordPaymentHref }}" class="comms-payment-cta">Record Payment</a>
                            @endif
                            @if($paymentDueText)
                                <span class="comms-payment-meta">{{ $paymentDueText }}</span>
                            @endif
                            @if($callTargetHref)
                                <a href="{{ $callTargetHref }}" class="comms-btn-soft">Call</a>
                            @endif
                            @if($followUp->whatsappUrl())
                                <a href="{{ $followUp->whatsappUrl() }}" target="_blank" rel="noopener" class="comms-btn-soft">WhatsApp</a>
                            @endif
                            <button type="button" class="comms-btn-ghost" data-comms-mark-read>Mark as Read</button>
                            @if(!$isClosed)
                                <form method="POST" action="{{ route('communication-center.complete', $followUp) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="comms-btn">Mark Complete</button>
                                </form>
                            @endif
                            @if($referenceHref)
                                <a href="{{ $referenceHref }}" class="comms-btn-ghost">View Related Record</a>
                            @endif
                            <button type="button"
                                    class="comms-btn-ghost"
                                    data-open-follow-up-modal
                                    data-follow-up-context='@json($noteContext)'
                                    data-follow-up-type="{{ \App\Models\FollowUp::TYPE_GENERAL }}"
                                    data-follow-up-priority="{{ \App\Models\FollowUp::PRIORITY_MEDIUM }}"
                                    data-follow-up-note="Follow-up note linked to {{ $followUp->referenceLabel() }}.">
                                Add Note
                            </button>
                        </div>

                        <div class="comms-detail-grid">
                            <div class="comms-thread-box">
                                <h3>Message / Timeline</h3>
                                <p>{{ $lastCommunication }}</p>
                                <p>{{ $followUp->typeLabel() }} follow-up from {{ $sourceName }}. Due {{ $followUp->dueLabel() }}.</p>
                            </div>

                            <div class="comms-kv">
                                <div class="comms-kv-item">
                                    <span>Due</span>
                                    <strong>{{ $followUp->dueLabel() }}</strong>
                                </div>
                                <div class="comms-kv-item">
                                    <span>Owner</span>
                                    <strong>{{ $followUp->assignedUser?->name ?? 'Unassigned' }}</strong>
                                </div>
                                <div class="comms-kv-item">
                                    <span>Status</span>
                                    <strong>{{ $followUp->statusLabel() }}</strong>
                                </div>
                                <div class="comms-kv-item">
                                    <span>Sender</span>
                                    <strong>{{ $sourceName }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="comms-card-contacts">
                            <div class="comms-contact-block">
                                <span class="comms-contact-label">Reminder / Payment</span>
                                <span class="comms-contact-value">{{ collect([$followUp->reminderContactName(), $followUp->reminderContactPhone()])->filter()->implode(' | ') ?: 'Not linked' }}</span>
                            </div>
                            <div class="comms-contact-block">
                                <span class="comms-contact-label">Service Location</span>
                                <span class="comms-contact-value">{{ collect([$followUp->serviceContactName(), $followUp->serviceContactPhone()])->filter()->implode(' | ') ?: 'Not linked' }}</span>
                            </div>
                            <div class="comms-contact-block">
                                <span class="comms-contact-label">Address</span>
                                <span class="comms-contact-value">{{ $followUp->serviceContactAddress() ?: 'No service address available' }}</span>
                                @if($followUp->serviceContactMapUrl())
                                    <a href="{{ $followUp->serviceContactMapUrl() }}" target="_blank" rel="noopener" class="comms-contact-link">Open Map</a>
                                @endif
                            </div>
                        </div>

                        @if(!$isClosed)
                            <div class="comms-note-strip" style="background:#f8fafc;border-color:#e2e8f0;">
                                <span>Reschedule / Reassign</span>
                                <form method="POST" action="{{ route('communication-center.reschedule', $followUp) }}" class="comms-reschedule-form">
                                    @csrf
                                    <div class="comms-modal-field">
                                        <label>Due Date & Time</label>
                                        <input class="comms-input" type="datetime-local" name="due_at" value="{{ optional($followUp->due_at)->format('Y-m-d\TH:i') }}">
                                    </div>
                                    <div class="comms-modal-field">
                                        <label>Assigned Staff</label>
                                        <select class="comms-select" name="assigned_user_id">
                                            <option value="">Unassigned</option>
                                            @foreach($assignableUsers as $user)
                                                <option value="{{ $user->id }}" @selected((int) $followUp->assigned_user_id === (int) $user->id)>{{ $user->name }} ({{ Str::headline($user->role ?: 'user') }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="comms-modal-field">
                                        <label>Priority</label>
                                        <select class="comms-select" name="priority">
                                            @foreach(\App\Models\FollowUp::PRIORITIES as $value => $label)
                                                <option value="{{ $value }}" @selected($followUp->priority === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="comms-modal-field">
                                        <label>Note</label>
                                        <input class="comms-input" type="text" name="reschedule_note" placeholder="Why moved">
                                    </div>
                                    <button type="submit" class="comms-btn-ghost">Save</button>
                                </form>
                            </div>
                        @endif
                    </div>
                </details>
            @empty
                <div class="comms-card comms-empty">
                    <strong>No communication items in this queue</strong>
                    <span>Try another tab or clear the current filters to see more follow-up work.</span>
                </div>
            @endforelse
        </section>

        @if($followUps instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            {{ $followUps->links() }}
        @endif
    @endif
</div>

@include('partials.follow-up-modal', [
    'followUpFeatureReady' => $featureReady,
    'followUpAssignableUsers' => $assignableUsers ?? collect(),
])

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const menus = Array.from(document.querySelectorAll('.comms-more'));
        const readStorageKey = 'phos.communication.readItems';
        let storedReadItems = [];
        try {
            storedReadItems = JSON.parse(localStorage.getItem(readStorageKey) || '[]');
        } catch (error) {
            storedReadItems = [];
        }
        const readItems = new Set(storedReadItems);
        const saveReadItems = () => {
            try {
                localStorage.setItem(readStorageKey, JSON.stringify(Array.from(readItems)));
            } catch (error) {
                // Read state is a convenience only; the inbox remains functional without browser storage.
            }
        };
        const markItemRead = (item) => {
            if (!item) {
                return;
            }

            readItems.add(String(item.dataset.commsId));
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            saveReadItems();
        };

        document.querySelectorAll('[data-comms-id]').forEach((item) => {
            if (readItems.has(String(item.dataset.commsId))) {
                item.classList.remove('is-unread');
                item.classList.add('is-read');
            }

            item.addEventListener('toggle', () => {
                if (item.open) {
                    markItemRead(item);
                }
            });
        });

        document.querySelectorAll('[data-comms-mark-read]').forEach((button) => {
            button.addEventListener('click', () => markItemRead(button.closest('[data-comms-id]')));
        });

        document.querySelector('[data-comms-mark-all-read]')?.addEventListener('click', () => {
            document.querySelectorAll('[data-comms-id]').forEach((item) => markItemRead(item));
        });

        const closeMenus = (except = null) => {
            menus.forEach((menu) => {
                if (menu !== except) {
                    menu.removeAttribute('open');
                }
            });
        };

        menus.forEach((menu) => {
            menu.addEventListener('toggle', () => {
                if (menu.open) {
                    closeMenus(menu);
                }
            });
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.comms-more')) {
                closeMenus();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenus();
            }
        });
    });
</script>
@endsection
