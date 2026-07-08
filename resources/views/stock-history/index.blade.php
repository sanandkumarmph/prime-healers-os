@extends('layouts.app')

@section('content')
@php
    $movementRows = $movements->getCollection();
    $activeFilterKeys = collect(['product_id','asset_id','warehouse_id','movement_type','from_date','to_date'])->filter(fn($key) => filled(request($key)));
    $activeFilterCount = $activeFilterKeys->count();
    $todayMovements = $movementRows->filter(fn($movement) => optional($movement->movement_at)->isSameDay(now()))->count();
    $countTypes = fn(array $types) => $movementRows->filter(fn($movement) => in_array((string) $movement->movement_type, $types, true))->count();
    $typeConfig = function (?string $type) {
        $map = [
            'purchase' => ['label' => 'Stock Added', 'tone' => 'green', 'icon' => 'package-plus', 'desc' => 'Purchase received'],
            'opening' => ['label' => 'Opening Balance', 'tone' => 'slate', 'icon' => 'archive', 'desc' => 'Opening stock recorded'],
            'import' => ['label' => 'Stock Imported', 'tone' => 'green', 'icon' => 'package-plus', 'desc' => 'Imported stock record'],
            'add_stock' => ['label' => 'Stock Added', 'tone' => 'green', 'icon' => 'package-plus', 'desc' => 'Stock received'],
            'sale' => ['label' => 'Sale Out', 'tone' => 'blue', 'icon' => 'cart', 'desc' => 'Sale stock moved out'],
            'delivery' => ['label' => 'Rental Delivered', 'tone' => 'blue', 'icon' => 'truck', 'desc' => 'Rental delivery started'],
            'rental_out' => ['label' => 'Rental Out', 'tone' => 'blue', 'icon' => 'truck', 'desc' => 'Asset moved to customer'],
            'pickup_return' => ['label' => 'Rental Returned', 'tone' => 'green', 'icon' => 'refresh', 'desc' => 'Rental returned'],
            'return_verification' => ['label' => 'Moved to Verification', 'tone' => 'amber', 'icon' => 'clipboard-check', 'desc' => 'Quality check required'],
            'repair' => ['label' => 'Moved to Repair', 'tone' => 'red', 'icon' => 'wrench', 'desc' => 'Repair workflow'],
            'scrap' => ['label' => 'Scrapped', 'tone' => 'red', 'icon' => 'wrench', 'desc' => 'Removed from usable stock'],
            'warehouse_transfer' => ['label' => 'Stock Transfer', 'tone' => 'purple', 'icon' => 'arrows', 'desc' => 'Warehouse transfer'],
            'manual_adjustment' => ['label' => 'Stock Adjustment', 'tone' => 'slate', 'icon' => 'sliders', 'desc' => 'Manual adjustment'],
            'correction_add' => ['label' => 'Correction Added', 'tone' => 'green', 'icon' => 'sliders', 'desc' => 'Correction increased stock'],
            'correction_remove' => ['label' => 'Correction Removed', 'tone' => 'red', 'icon' => 'sliders', 'desc' => 'Correction reduced stock'],
            'correction_transfer' => ['label' => 'Correction Transfer', 'tone' => 'purple', 'icon' => 'arrows', 'desc' => 'Correction transfer'],
        ];
        return $map[$type] ?? ['label' => str($type ?: 'movement')->replace('_',' ')->title()->toString(), 'tone' => 'slate', 'icon' => 'sliders', 'desc' => 'Stock movement'];
    };
    $statusLabel = fn($value) => filled($value) ? str($value)->replace('_',' ')->title()->toString() : 'Not set';
    $locationLabel = fn($status, $warehouse) => trim(collect([$status ? $statusLabel($status) : null, $warehouse?->name])->filter()->implode(' / ')) ?: 'Not set';
    $thumbInitial = fn($product) => strtoupper(mb_substr((string) ($product?->name ?: 'P'), 0, 1));
    $groupedMovements = $movementRows->groupBy(fn($movement) => optional($movement->movement_at)->format('Y-m-d') ?: 'undated');
    $typeCounts = $movementRows->groupBy('movement_type')->map->count()->sortDesc();
    $maxTypeCount = max(1, (int) $typeCounts->max());
    $recentActivity = $movementRows->take(5);
    $quickUrl = fn(array $params) => route('stock-history.index', array_merge(request()->except('page'), $params));
    $iconSvg = function (?string $icon, string $class = 'stock-svg-icon') {
        $attrs = 'class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
        $paths = [
            'package-plus' => '<path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="m3.3 17 8.7 5 8.7-5"/><path d="M3.3 7 12 2l8.7 5v10L12 22 3.3 17Z"/><path d="M17 3v5"/><path d="M14.5 5.5h5"/>',
            'archive' => '<path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/>',
            'truck' => '<path d="M10 17h4V5H2v12h3"/><path d="M14 8h4l4 4v5h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="17.5" r="2.5"/>',
            'refresh' => '<path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M16 8h5V3"/>',
            'cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.1 2.1h2l2.7 12.4a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6l1.4-7.4H5.1"/>',
            'arrows' => '<path d="M7 7h11l-3-3"/><path d="M18 7l-3 3"/><path d="M17 17H6l3 3"/><path d="M6 17l3-3"/>',
            'clipboard-check' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path d="M9 5a3 3 0 0 1 6 0v0H9v0Z"/><path d="m9 14 2 2 4-4"/>',
            'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.4 2.4-3-3 2.4-2.4Z"/>',
            'sliders' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
            'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
            'filter' => '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3Z"/>',
        ];
        return '<svg ' . $attrs . '>' . ($paths[$icon] ?? $paths['sliders']) . '</svg>';
    };
@endphp

<style>
.stock-history-shell{display:grid;gap:10px;padding-bottom:84px}.stock-card{background:#fff;border:1px solid #dbe7f3;border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.045)}.stock-header{padding:14px 16px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.stock-breadcrumb{display:flex;gap:8px;align-items:center;color:#64748b;font-size:12px;font-weight:800}.stock-title{margin:4px 0 2px;font-size:25px;line-height:1.05;color:#0f172a}.stock-subtitle{margin:0;color:#52647d;font-size:13px}.stock-actions{display:flex;gap:10px;flex-wrap:wrap}.stock-action-btn,.stock-chip,.stock-icon-btn{border:1px solid #cbd8ea;background:#fff;color:#172033;border-radius:12px;height:36px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:800;text-decoration:none;cursor:pointer}.stock-action-btn.primary,.stock-chip.active{background:#4f46e5;color:#fff;border-color:#4f46e5}.stock-filter-count{min-width:20px;height:20px;border-radius:999px;background:#4f46e5;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px}.stock-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.stock-kpi{padding:10px 12px;min-height:78px;display:flex;align-items:center;gap:10px}.stock-kpi-icon,.movement-dot,.product-thumb,.actor-avatar{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;border-radius:14px;font-weight:900}.stock-kpi-icon{width:36px;height:36px}.stock-kpi strong{display:block;font-size:20px;line-height:1;color:#0f172a;margin:2px 0 4px}.stock-kpi span,.stock-muted{font-size:12px;color:#64748b}.stock-kpi label{font-size:12px;color:#475569;font-weight:800}.tone-green{--tone:#16a34a;--tone-bg:#dcfce7}.tone-blue{--tone:#2563eb;--tone-bg:#dbeafe}.tone-amber{--tone:#d97706;--tone-bg:#ffedd5}.tone-red{--tone:#dc2626;--tone-bg:#fee2e2}.tone-purple{--tone:#7c3aed;--tone-bg:#ede9fe}.tone-slate{--tone:#64748b;--tone-bg:#e2e8f0}.tone-box{background:var(--tone-bg);color:var(--tone)}.stock-control-card{padding:10px;display:grid;gap:10px}.stock-chip-row{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px}.stock-chip{height:32px;padding:0 12px;font-size:12px;white-space:nowrap}.stock-search-row{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px}.stock-search{height:42px;border:1px solid #cbd8ea;border-radius:12px;padding:0 14px;color:#0f172a;background:#fff;width:100%}.stock-filters-panel{padding:14px;border-top:1px solid #e5edf7;display:grid;gap:12px}.stock-filters-grid{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:10px}.stock-filter-field label{display:block;margin-bottom:5px;font-size:11px;color:#64748b;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
</style>
<style>
.stock-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,300px);gap:10px;align-items:start}.timeline-card{overflow:visible}.timeline-head{padding:14px 16px;border-bottom:1px solid #e5edf7;display:flex;align-items:center;justify-content:space-between;gap:10px}.timeline-head h2,.side-title{margin:0;font-size:17px;color:#0f172a}.date-group{border-bottom:1px solid #e5edf7}.date-title{padding:13px 16px;display:flex;align-items:center;gap:10px;background:#fbfdff;font-weight:900;color:#0f172a}.date-count{padding:5px 9px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:12px}.movement-row{display:grid;grid-template-columns:34px 70px minmax(150px,.9fr) minmax(190px,1fr) minmax(150px,.72fr) 38px;gap:10px;padding:10px 14px;border-top:1px solid #edf2f7;align-items:center;background:#fff}.movement-row:hover{background:#fbfdff}.movement-dot{width:32px;height:32px;background:var(--tone);color:#fff;box-shadow:0 8px 18px rgba(15,23,42,.14)}.movement-time{color:#475569;font-size:13px;font-weight:800}.movement-type{display:grid;gap:3px}.movement-type strong{color:var(--tone);font-size:14px}.movement-type span{color:#64748b;font-size:12px}.product-cell{display:flex;gap:10px;min-width:0;align-items:center}.product-thumb{width:42px;height:42px;border:1px solid #dbe7f3;background:#f1f5f9;color:#4f46e5;overflow:hidden}.product-thumb img{width:100%;height:100%;object-fit:cover;display:block}.product-copy{min-width:0;display:grid;gap:3px}.product-copy strong{color:#0f172a;font-size:14px;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.product-copy span{color:#64748b;font-size:12px}.route-cell{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);gap:9px;align-items:center;font-size:13px;color:#172033}.route-label{display:grid;gap:2px;min-width:0}.route-label small{color:#64748b;font-size:11px}.route-arrow{color:#64748b;font-weight:900}.performed-cell{display:flex;gap:8px;align-items:center;min-width:0}.actor-avatar{width:34px;height:34px;background:#eef2ff;color:#4338ca;font-size:12px}.performed-cell strong{font-size:13px;color:#172033;display:block}.status-pill{display:inline-flex;align-items:center;justify-content:center;width:fit-content;padding:5px 9px;border-radius:999px;background:var(--tone-bg);color:var(--tone);font-size:11px;font-weight:900}.side-stack{display:grid;gap:12px;position:sticky;top:88px}.side-card{padding:12px}.summary-list{display:grid;margin-top:10px}.summary-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #edf2f7;color:#475569;font-size:13px}.summary-row strong{color:#0f172a}.bar-row{display:grid;grid-template-columns:minmax(90px,1fr) 44px;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #edf2f7}.bar-track{height:8px;border-radius:999px;background:#eef2f7;overflow:hidden;margin-top:4px}.bar-fill{height:100%;border-radius:inherit;background:#4f46e5}.activity-row{display:grid;grid-template-columns:34px minmax(0,1fr);gap:9px;padding:9px 0;border-bottom:1px solid #edf2f7}.empty-state{padding:28px 16px;color:#64748b;text-align:center}.stock-pagination{padding:14px 16px;border-top:1px solid #e5edf7}.details-popover summary{list-style:none}.details-popover summary::-webkit-details-marker{display:none}.details-panel{position:fixed;right:28px;top:220px;width:min(340px,calc(100vw - 40px));max-height:70vh;overflow:auto;background:#fff;border:1px solid #cbd8ea;border-radius:14px;box-shadow:0 18px 42px rgba(15,23,42,.14);padding:14px;z-index:20;display:grid;gap:8px}.detail-line{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:#475569}.detail-line strong{color:#0f172a;text-align:right}@media(max-width:1180px){.stock-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.stock-layout{grid-template-columns:1fr}.side-stack{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.stock-history-shell{gap:10px;padding-bottom:110px}.stock-header{padding:14px}.stock-title{font-size:24px}.stock-actions{width:100%}.stock-action-btn{flex:1 1 auto;height:38px}.stock-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.stock-kpi{padding:11px}.stock-kpi-icon{width:34px;height:34px;border-radius:11px}.stock-kpi strong{font-size:19px}.stock-search-row,.stock-filters-grid{grid-template-columns:1fr}.movement-row{grid-template-columns:34px minmax(0,1fr) 40px;gap:9px;padding:12px;align-items:start}.movement-time,.movement-type{grid-column:2/3}.product-cell,.route-cell{grid-column:1/4}.performed-cell{grid-column:1/3}.details-popover{grid-column:3/4;grid-row:1/2;justify-self:end;position:relative}.product-thumb{width:42px;height:42px}.side-stack{grid-template-columns:1fr}.timeline-head,.date-title{padding:12px}}
</style>


<style>
.mobile-movement-actions{display:none}.details-popover[open] .stock-icon-btn{background:#eef2ff;border-color:#a5b4fc;color:#3730a3}.details-popover[open] .details-panel{animation:stockPopoverIn .14s ease-out}.stock-sheet-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:900;opacity:0;pointer-events:none;transition:opacity .16s ease}.stock-sheet-backdrop.is-open{opacity:1;pointer-events:auto}@keyframes stockPopoverIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}@media(max-width:760px){body.stock-sheet-open{overflow:hidden}.stock-header{position:relative}.stock-control-card{order:3}.stock-chip-row{order:2;scrollbar-width:none}.stock-chip-row::-webkit-scrollbar{display:none}.stock-search-row{order:1}.stock-filters-panel{position:fixed;left:0;right:0;bottom:0;z-index:1001;max-height:82vh;overflow:auto;background:#fff;border:1px solid #dbe7f3;border-radius:22px 22px 0 0;box-shadow:0 -18px 44px rgba(15,23,42,.22);padding:16px 14px 96px;display:grid;gap:10px}.stock-control-card details:not([open]) .stock-filters-panel{display:none}.stock-control-card details[open] summary{position:relative;z-index:1002}.stock-filter-field label{font-size:10px;margin-bottom:4px}.date-title{position:sticky;top:0;z-index:5}.movement-row{position:relative;margin:8px 10px;border:1px solid #dbe7f3;border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.05);grid-template-columns:38px minmax(0,1fr);overflow:hidden}.movement-row::before{content:"";position:absolute;left:18px;top:-10px;bottom:-10px;width:2px;background:#e2e8f0}.movement-dot{grid-column:1;grid-row:1 / span 2;z-index:1}.movement-type{grid-column:2;grid-row:1}.movement-type strong{font-size:14px}.movement-time{grid-column:2;grid-row:2;font-size:12px}.product-cell{grid-column:1 / -1;display:grid;grid-template-columns:46px minmax(0,1fr);padding-top:4px}.product-thumb{width:46px;height:46px;border-radius:12px}.route-cell{grid-column:1 / -1;grid-template-columns:1fr 24px 1fr;background:#f8fafc;border:1px solid #edf2f7;border-radius:12px;padding:10px}.route-label strong{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.performed-cell{grid-column:1 / -1;background:#fff;align-items:center}.status-pill{min-height:24px}.mobile-movement-actions{grid-column:1 / -1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.mobile-action-btn{min-height:40px;border:1px solid #cbd8ea;background:#fff;border-radius:12px;font-weight:900;color:#334155}.mobile-action-btn.more{background:#0f172a;color:#fff;border-color:#0f172a}.details-popover{grid-column:1 / -1;position:static!important}.details-popover>summary{display:none}.details-panel{position:fixed!important;left:0!important;right:0!important;top:auto!important;bottom:0!important;width:100%!important;max-height:78vh!important;z-index:1002!important;border-radius:22px 22px 0 0!important;border:1px solid #dbe7f3!important;box-shadow:0 -18px 44px rgba(15,23,42,.24)!important;padding:18px 16px calc(22px + env(safe-area-inset-bottom))!important;animation:stockSheetIn .18s ease-out!important}.details-panel::before{content:"";width:44px;height:5px;border-radius:999px;background:#cbd5e1;justify-self:center;margin-bottom:4px}.detail-line{font-size:13px;padding:4px 0}.side-stack{display:none}.stock-pagination{padding-bottom:28px}.stock-history-shell{padding-bottom:132px;overflow-x:hidden}}@keyframes stockSheetIn{from{transform:translateY(18px);opacity:.8}to{transform:translateY(0);opacity:1}}
</style>

<style>
.stock-svg-icon{width:18px;height:18px;display:block}.stock-action-short{display:none}.detail-sheet-title{font-weight:900;color:#0f172a;font-size:15px;padding:2px 0 6px;border-bottom:1px solid #edf2f7}.details-popover[data-mode="view"] .detail-more,.details-popover[data-mode="view"] .detail-timeline{display:none}.details-popover[data-mode="timeline"] .detail-more,.details-popover[data-mode="timeline"] .detail-summary{display:none}.details-popover[data-mode="more"] .detail-summary{display:none}@media(max-width:760px){.stock-header{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:10px}.stock-subtitle{font-size:12px;line-height:1.35}.stock-actions{width:auto;display:flex;flex-wrap:nowrap;gap:7px;justify-content:flex-end}.stock-header-btn{width:40px;min-width:40px;height:40px;padding:0;border-radius:12px;position:relative;flex:0 0 auto}.stock-header-btn .stock-action-label{display:none}.stock-header-btn .stock-action-short{display:inline;font-size:11px}.stock-header-btn .stock-filter-count{position:absolute;top:-7px;right:-7px}.stock-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.stock-kpi{min-height:58px;padding:8px 9px;border-radius:12px;gap:8px}.stock-kpi-icon{width:30px;height:30px;border-radius:10px}.stock-kpi-icon .stock-svg-icon,.movement-dot .stock-svg-icon{width:17px;height:17px}.stock-kpi strong{font-size:17px;margin:0}.stock-kpi label{font-size:10px;line-height:1.15}.stock-kpi span:not(.stock-kpi-icon){display:none}.timeline-head{padding:10px 12px}.timeline-head h2{font-size:15px}.date-title{padding:9px 12px;font-size:13px}.movement-row{margin:7px 8px;padding:9px;gap:6px;grid-template-columns:34px minmax(0,1fr);border-radius:14px}.movement-row::before{display:none!important}.movement-dot{width:32px;height:32px;grid-row:1 / span 2}.movement-type{gap:0}.movement-type strong{font-size:13px;line-height:1.2}.movement-desc,.movement-type .status-pill{display:none!important}.movement-time{font-size:11px;line-height:1.2}.product-cell{grid-template-columns:42px minmax(0,1fr);gap:8px;padding-top:5px}.product-thumb{width:42px;height:42px}.product-copy{gap:1px}.product-copy strong{font-size:13px;line-height:1.2;-webkit-line-clamp:2}.product-copy span{font-size:11px;line-height:1.2}.route-cell{border:0;background:transparent;padding:2px 0;gap:5px;font-size:11px}.route-label{display:block}.route-label small{display:inline;font-size:10px;margin-right:3px}.route-label strong{display:inline;white-space:nowrap;max-width:118px;vertical-align:bottom;text-overflow:ellipsis}.route-arrow{font-size:12px}.performed-cell{gap:6px;padding-top:2px}.actor-avatar{width:26px;height:26px;font-size:10px}.performed-cell strong{font-size:12px}.performed-cell .stock-muted{font-size:11px}.mobile-movement-actions{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #edf2f7;padding-top:7px}.mobile-action-btn{width:40px;height:40px;min-height:40px;padding:0;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:#334155}.mobile-action-btn.more{background:#0f172a;color:#fff}.mobile-action-btn span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.details-popover[data-mode="view"] .detail-summary,.details-popover[data-mode="timeline"] .detail-timeline,.details-popover[data-mode="more"] .detail-more{display:flex}.detail-sheet-title{font-size:16px}.detail-line{font-size:13px;padding:7px 0;border-bottom:1px solid #f1f5f9}.stock-pagination{padding-bottom:36px}}
</style><div class="stock-history-shell">
    <section class="stock-card stock-header">
        <div style="min-width:0;">
            <div class="stock-breadcrumb"><span>Inventory</span><span>/</span><strong style="color:#0f172a;">Stock History</strong></div>
            <h1 class="stock-title">Stock History</h1>
            <p class="stock-subtitle">Complete audit trail of stock movements across warehouses, assets, rentals, and sales.</p>
        </div>
        <div class="stock-actions">
            @if($canExportStockHistory)
                <a href="{{ route('stock-history.export.csv', request()->query()) }}" class="stock-action-btn stock-header-btn" title="Export CSV" aria-label="Export stock history CSV">{!! $iconSvg('download') !!}<span class="stock-action-label">Export CSV</span><span class="stock-action-short">CSV</span></a>
            @endif
            <a href="#stock-history-filters" class="stock-action-btn stock-header-btn" title="Filters" aria-label="Open stock history filters">{!! $iconSvg('filter') !!}<span class="stock-action-label">Filters</span>@if($activeFilterCount > 0)<span class="stock-filter-count">{{ $activeFilterCount }}</span>@endif</a>
        </div>
    </section>

    <section class="stock-kpis">
        @php
            $kpis = [
                ['label' => "Today's Movements", 'value' => $todayMovements, 'helper' => 'Current page today', 'tone' => 'purple', 'icon' => 'sliders'],
                ['label' => 'Rental Out', 'value' => $countTypes(['rental_out','delivery']), 'helper' => 'Assets sent out', 'tone' => 'blue', 'icon' => 'truck'],
                ['label' => 'Returns', 'value' => $countTypes(['pickup_return']), 'helper' => 'Returned assets', 'tone' => 'green', 'icon' => 'refresh'],
                ['label' => 'Transfers', 'value' => $countTypes(['warehouse_transfer','correction_transfer']), 'helper' => 'Warehouse moves', 'tone' => 'amber', 'icon' => 'arrows'],
                ['label' => 'Verifications', 'value' => $countTypes(['return_verification']), 'helper' => 'Quality checks', 'tone' => 'purple', 'icon' => 'clipboard-check'],
                ['label' => 'Repairs', 'value' => $countTypes(['repair','scrap']), 'helper' => 'Repair or scrap', 'tone' => 'red', 'icon' => 'wrench'],
            ];
        @endphp
        @foreach($kpis as $kpi)
            <article class="stock-card stock-kpi tone-{{ $kpi['tone'] }}">
                <span class="stock-kpi-icon tone-box" aria-hidden="true">{!! $iconSvg($kpi['icon']) !!}</span>
                <div style="min-width:0;"><label>{{ $kpi['label'] }}</label><strong>{{ number_format($kpi['value']) }}</strong><span>{{ $kpi['helper'] }}</span></div>
            </article>
        @endforeach
    </section>
    <section class="stock-card stock-control-card" id="stock-history-filters">
        <div class="stock-chip-row" aria-label="Quick date filters">
            <a class="stock-chip {{ request('from_date') === now()->toDateString() && request('to_date') === now()->toDateString() ? 'active' : '' }}" href="{{ $quickUrl(['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}">Today</a>
            <a class="stock-chip" href="{{ $quickUrl(['from_date' => now()->subDay()->toDateString(), 'to_date' => now()->subDay()->toDateString()]) }}">Yesterday</a>
            <a class="stock-chip" href="{{ $quickUrl(['from_date' => now()->startOfWeek()->toDateString(), 'to_date' => now()->endOfWeek()->toDateString()]) }}">This Week</a>
            <a class="stock-chip" href="{{ $quickUrl(['from_date' => now()->startOfMonth()->toDateString(), 'to_date' => now()->endOfMonth()->toDateString()]) }}">This Month</a>
            <a class="stock-chip" href="#stock-history-filters">Movement Type</a>
            <a class="stock-chip" href="#stock-history-filters">Warehouse</a>
            <a class="stock-chip" href="#stock-history-filters">Product</a>
            <a class="stock-chip" href="#stock-history-filters">Asset</a>
        </div>
        <form method="GET" action="{{ route('stock-history.index') }}" style="display:grid;gap:12px;">
            <div class="stock-search-row">
                <input class="stock-search" type="search" name="search_hint" value="" placeholder="Search by product, asset, serial, rental, customer, invoice..." aria-label="Search stock history">
                <button type="submit" class="stock-action-btn primary">Apply</button>
            </div>
            <details>
                <summary class="stock-action-btn" style="width:max-content;">Advanced filters @if($activeFilterCount > 0)<span class="stock-filter-count">{{ $activeFilterCount }}</span>@endif</summary>
                <div class="stock-filters-panel">
                    <div class="stock-filters-grid">
                        <div class="stock-filter-field"><label>Product</label><select name="product_id" class="ph-input" style="width:100%;height:40px;"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ trim(collect([$product->name, $product->brand, $product->model_name])->filter()->implode(' - ')) }}</option>@endforeach</select></div>
                        <div class="stock-filter-field"><label>Asset</label><select name="asset_id" class="ph-input" style="width:100%;height:40px;"><option value="">All assets</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected((string) request('asset_id') === (string) $asset->id)>{{ $asset->serial_number ?: ('Asset #' . $asset->id) }}@if($asset->barcode_value) - {{ $asset->barcode_value }}@endif</option>@endforeach</select></div>
                        <div class="stock-filter-field"><label>Warehouse</label><select name="warehouse_id" class="ph-input" style="width:100%;height:40px;"><option value="">All warehouses</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) request('warehouse_id') === (string) $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
                                                <div class="stock-filter-field">
                            <label>Movement Type</label>
                            <select name="movement_type" class="ph-input" style="width:100%;height:40px;">
                                <option value="">All types</option>
                                @foreach($movementTypes as $movementType)
                                    @php
                                        $config = $typeConfig($movementType);
                                    @endphp
                                    <option value="{{ $movementType }}" @selected((string) request('movement_type') === (string) $movementType)>{{ $config['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="stock-filter-field"><label>From</label><input type="date" name="from_date" value="{{ request('from_date') }}" class="ph-input" style="width:100%;height:40px;"></div>
                        <div class="stock-filter-field"><label>To</label><input type="date" name="to_date" value="{{ request('to_date') }}" class="ph-input" style="width:100%;height:40px;"></div>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;"><a href="{{ route('stock-history.index') }}" class="stock-action-btn">Reset</a><button type="submit" class="stock-action-btn primary">Apply Filters</button></div>
                </div>
            </details>
        </form>
    </section>

    <div class="stock-layout">
        <section class="stock-card timeline-card">
            <div class="timeline-head">
                <div><h2>Inventory Activity Explorer</h2><div class="stock-muted">{{ number_format($movements->total()) }} movement{{ $movements->total() === 1 ? '' : 's' }} matched</div></div>
                <span class="status-pill tone-slate">Audit ledger</span>
            </div>
            @forelse($groupedMovements as $date => $dateMovements)
                <div class="date-group">
                    <div class="date-title"><span>{{ $date === 'undated' ? 'Date not set' : \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</span><span class="date-count">{{ $dateMovements->count() }} movement{{ $dateMovements->count() === 1 ? '' : 's' }}</span></div>
                    @foreach($dateMovements as $movement)
                        @php
                            $config = $typeConfig((string) $movement->movement_type);
                            $product = $movement->product;
                            $productName = trim(collect([$product?->name, $product?->brand, $product?->model_name])->filter()->implode(' - ')) ?: 'Product not linked';
                            $imageUrl = $product?->product_image_url;
                            $fromText = $locationLabel($movement->from_status, $movement->fromWarehouse);
                            $toText = $locationLabel($movement->to_status, $movement->toWarehouse);
                            $actorName = $movement->performedBy?->name ?: 'System';
                            $actorInitials = collect(explode(' ', $actorName))->filter()->map(fn($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: 'S';
                        @endphp
                        <article class="movement-row tone-{{ $config['tone'] }}" data-stock-movement-row>
                            <span class="movement-dot" aria-hidden="true">{!! $iconSvg($config['icon']) !!}</span>
                            <div class="movement-time">{{ optional($movement->movement_at)->format('h:i A') ?: '--' }}</div>
                            <div class="movement-type"><strong>{{ $config['label'] }}</strong><span class="movement-desc">{{ $movement->notes ?: $config['desc'] }}</span><span class="status-pill tone-{{ $config['tone'] }}">{{ $statusLabel($movement->to_status ?: $movement->movement_type) }}</span></div>
                                                        <div class="product-cell">
                                <span class="product-thumb" aria-hidden="true">
                                    @if($imageUrl)
                                        <img src="{{ $imageUrl }}" alt="" onerror="this.parentElement.textContent='{{ $thumbInitial($product) }}'">
                                    @else
                                        {{ $thumbInitial($product) }}
                                    @endif
                                </span>
                                <div class="product-copy">
                                    <strong>{{ $productName }}</strong>
                                    <span>Qty {{ $movement->quantity }}</span>
                                    @if($movement->asset)
                                        <span>Asset {{ $movement->asset->serial_number ?: ('#' . $movement->asset->id) }}</span>
                                        @if($movement->asset->barcode_value)
                                            <span>Serial {{ $movement->asset->barcode_value }}</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <div class="route-cell"><span class="route-label"><small>From</small><strong>{{ $fromText }}</strong></span><span class="route-arrow">to</span><span class="route-label"><small>To</small><strong>{{ $toText }}</strong></span></div>
                            <div class="performed-cell"><span class="actor-avatar" aria-hidden="true">{{ $actorInitials }}</span><span style="min-width:0;"><strong>{{ $actorName }}</strong><span class="stock-muted">{{ optional($movement->movement_at)->format('h:i A') ?: '' }}</span></span></div>
                            <div class="desktop-movement-actions" aria-label="Movement actions">
                                <button type="button" class="stock-icon-btn" data-stock-open-details="view" aria-label="View movement summary" title="View">{!! $iconSvg('eye') !!}</button>
                                @if($movement->asset_id || $movement->product_id)
                                    <button type="button" class="stock-icon-btn" data-stock-open-details="timeline" aria-label="Open movement timeline" title="Timeline">{!! $iconSvg('clock') !!}</button>
                                @endif
                                <button type="button" class="stock-icon-btn" data-stock-open-details="more" aria-label="More movement actions" title="More">{!! $iconSvg('more') !!}</button>
                            </div>
                            <div class="mobile-movement-actions" aria-label="Movement actions">
                                <button type="button" class="mobile-action-btn" data-stock-open-details="view" aria-label="View movement summary" title="View">{!! $iconSvg('eye') !!}<span>View</span></button>
                                @if($movement->asset_id || $movement->product_id)
                                    <button type="button" class="mobile-action-btn" data-stock-open-details="timeline" aria-label="Open movement timeline" title="Timeline">{!! $iconSvg('clock') !!}<span>Timeline</span></button>
                                @endif
                                <button type="button" class="mobile-action-btn more" data-stock-open-details="more" aria-label="More movement actions" title="More">{!! $iconSvg('more') !!}<span>More</span></button>
                            </div>
                            <details class="details-popover" data-stock-popover data-mode="more">
                                <summary class="stock-icon-btn stock-popover-fallback" title="Movement details" aria-label="Movement details" data-stock-popover-trigger>{!! $iconSvg('more') !!}</summary>
                                <div class="details-panel" data-stock-popover-panel role="dialog" aria-modal="false" aria-label="Movement details">
                                    <div class="detail-sheet-title" data-detail-title>Movement Details</div>
                                    <div class="detail-line detail-summary"><span>Movement</span><strong>{{ $config['label'] }}</strong></div>
                                    <div class="detail-line detail-timeline"><span>Timestamp</span><strong>{{ optional($movement->movement_at)->format('d M Y, h:i A') ?: 'Not set' }}</strong></div>
                                    <div class="detail-line detail-summary"><span>Product</span><strong>{{ $productName }}</strong></div>
                                    <div class="detail-line detail-summary"><span>Quantity</span><strong>{{ $movement->quantity }}</strong></div>
                                    <div class="detail-line detail-timeline"><span>From</span><strong>{{ $fromText }}</strong></div>
                                    <div class="detail-line detail-timeline"><span>To</span><strong>{{ $toText }}</strong></div>
                                    @if($movement->rental_id)<div class="detail-line detail-more"><span>Rental</span><strong>#{{ $movement->rental_id }}</strong></div>@endif
                                    @if($movement->sale_id)<div class="detail-line detail-more"><span>Sale</span><strong>#{{ $movement->sale_id }}</strong></div>@endif
                                    @if($movement->delivery_id)<div class="detail-line detail-more"><span>Delivery</span><strong>#{{ $movement->delivery_id }}</strong></div>@endif
                                    @if($movement->invoice)<div class="detail-line detail-more"><span>Invoice</span><strong>{{ $movement->invoice->invoice_number }}</strong></div>@endif
                                    <div class="detail-line detail-timeline"><span>Performed by</span><strong>{{ $actorName }}</strong></div>
                                    <div class="detail-line detail-more"><span>Ledger ref</span><strong>#{{ $movement->id }}</strong></div>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>
            @empty
                <div class="empty-state">No stock movement history matched the selected filters yet.</div>
            @endforelse
            <div class="stock-pagination">{{ $movements->links() }}</div>
        </section>

        <aside class="side-stack" aria-label="Stock history summary">
            <section class="stock-card side-card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;"><h2 class="side-title">Quick Summary</h2><span class="status-pill tone-blue">Live</span></div>
                <div class="summary-list">
                    <div class="summary-row"><span>Total Movements</span><strong>{{ number_format($movements->total()) }}</strong></div>
                    <div class="summary-row"><span>In Stock</span><strong>{{ $movementRows->where('to_status', 'available')->count() }}</strong></div>
                    <div class="summary-row"><span>Rented Out</span><strong>{{ $countTypes(['rental_out','delivery']) }}</strong></div>
                    <div class="summary-row"><span>In Verification</span><strong>{{ $countTypes(['return_verification']) }}</strong></div>
                    <div class="summary-row"><span>In Repair</span><strong>{{ $countTypes(['repair']) }}</strong></div>
                    <div class="summary-row"><span>Transferred</span><strong>{{ $countTypes(['warehouse_transfer','correction_transfer']) }}</strong></div>
                    <div class="summary-row"><span>Adjusted</span><strong>{{ $countTypes(['manual_adjustment','correction_add','correction_remove']) }}</strong></div>
                </div>
            </section>

            <section class="stock-card side-card">
                <h2 class="side-title">Top Movement Types</h2>
                <div style="margin-top:10px;display:grid;gap:2px;">
                    @forelse($typeCounts->take(6) as $type => $count)
                        @php
                            $mixConfig = $typeConfig((string) $type);
                            $mixPercentage = round(($count / max(1, $movementRows->count())) * 100);
                            $mixWidth = max(6, round(($count / $maxTypeCount) * 100));
                        @endphp
                        <div class="bar-row tone-{{ $mixConfig['tone'] }}">
                            <div>
                                <div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;font-weight:800;color:#172033;">
                                    <span>{{ $mixConfig['label'] }}</span>
                                    <span>{{ $mixPercentage }}%</span>
                                </div>
                                <div class="bar-track"><div class="bar-fill" style="width:{{ $mixWidth }}%;background:var(--tone);"></div></div>
                            </div>
                            <strong style="text-align:right;">{{ $count }}</strong>
                        </div>
                    @empty
                        <p class="stock-muted">No movement mix yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="stock-card side-card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;"><h2 class="side-title">Filters Applied</h2>@if($activeFilterCount > 0)<a href="{{ route('stock-history.index') }}" style="font-size:12px;font-weight:800;color:#dc2626;text-decoration:none;">Clear all</a>@endif</div>
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:10px;">
                    @forelse($activeFilterKeys as $key)
                        <span class="status-pill tone-slate">{{ str($key)->replace('_', ' ')->title() }}: {{ request($key) }}</span>
                    @empty
                        <span class="stock-muted">No filters applied.</span>
                    @endforelse
                </div>
            </section>

            <section class="stock-card side-card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;"><h2 class="side-title">Recent Activity</h2><span class="stock-muted">Latest {{ $recentActivity->count() }}</span></div>
                <div style="margin-top:8px;">
                    @forelse($recentActivity as $movement)
                        @php
                            $config = $typeConfig((string) $movement->movement_type);
                            $actorName = $movement->performedBy?->name ?: 'System';
                            $actorInitials = collect(explode(' ', $actorName))->filter()->map(fn($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: 'S';
                        @endphp
                        <div class="activity-row tone-{{ $config['tone'] }}"><span class="actor-avatar tone-box" aria-hidden="true">{{ $actorInitials }}</span><div style="min-width:0;"><strong style="display:block;font-size:13px;color:#0f172a;">{{ $actorName }}</strong><span class="stock-muted">{{ $config['label'] }} - {{ optional($movement->movement_at)->format('h:i A') }}</span></div></div>
                    @empty
                        <p class="stock-muted">No recent activity.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>


<style>
/* Compact Stock History refinement */
.stock-header{padding:12px 14px}.stock-title{font-size:23px}.stock-subtitle{font-size:12px}.stock-kpis{gap:7px}.stock-kpi{min-height:62px;padding:8px 10px;border-radius:12px}.stock-kpi-icon{width:32px;height:32px}.stock-kpi strong{font-size:18px;margin:1px 0}.stock-kpi label{font-size:11px}.stock-kpi span:not(.stock-kpi-icon){font-size:11px}.timeline-head{padding:11px 14px}.timeline-head h2{font-size:16px}.date-title{padding:10px 14px;font-size:14px}.date-count{padding:4px 8px;font-size:11px}.movement-row{grid-template-columns:30px 58px minmax(112px,.62fr) minmax(168px,1fr) minmax(118px,.58fr) minmax(102px,.52fr) 112px;gap:8px;padding:8px 12px}.movement-dot{width:30px;height:30px;border-radius:11px}.movement-time{font-size:12px}.movement-type strong{font-size:13px}.movement-desc{display:none}.status-pill{font-size:10px;padding:4px 8px}.product-thumb{width:38px;height:38px;border-radius:11px}.product-copy strong{font-size:13px}.product-copy span{font-size:11px}.route-cell{font-size:12px;gap:6px}.route-label small{font-size:10px}.performed-cell{gap:6px}.actor-avatar{width:30px;height:30px}.performed-cell strong{font-size:12px}.desktop-movement-actions{grid-column:7;display:flex;align-items:center;justify-content:flex-end;gap:6px;min-width:0}.desktop-movement-actions .stock-icon-btn{width:32px;height:32px;padding:0;border-radius:10px}.stock-popover-fallback{display:none!important}@media(max-width:760px){.desktop-movement-actions{display:none}.stock-popover-fallback{display:none!important}.stock-kpi{min-height:54px;padding:7px 8px}.stock-kpi-icon{width:28px;height:28px}.stock-kpi strong{font-size:16px}.timeline-head{padding:9px 11px}.date-title{padding:8px 10px}.movement-row{padding:8px;margin:6px 8px}.movement-dot{width:30px;height:30px}.product-thumb{width:40px;height:40px}.route-cell{font-size:11px}.mobile-movement-actions{padding-top:6px}.mobile-action-btn{width:38px;height:38px;min-height:38px}}
/* Horizontal activity explorer rail */
.timeline-card{overflow-x:auto;overscroll-behavior-x:contain;scrollbar-gutter:stable;}
.timeline-card .date-group{min-width:1040px;}
.timeline-card::-webkit-scrollbar{height:10px;}
.timeline-card::-webkit-scrollbar-track{background:#f1f5f9;border-radius:999px;}
.timeline-card::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px;}
.timeline-card::-webkit-scrollbar-thumb:hover{background:#94a3b8;}
@media(max-width:760px){.timeline-card{overflow-x:hidden;}.timeline-card .date-group{min-width:0;}}
</style><script>
document.addEventListener('DOMContentLoaded', function () {
    const mobileQuery = window.matchMedia('(max-width: 760px)');
    let activePopover = null;
    let activeFilterSheet = null;
    let sheetHistoryOpen = false;
    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'stock-sheet-backdrop';
    backdrop.setAttribute('aria-label', 'Close panel');
    document.body.appendChild(backdrop);

    function isMobile() {
        return mobileQuery.matches;
    }

    function setBackdrop(open) {
        backdrop.classList.toggle('is-open', open);
        document.body.classList.toggle('stock-sheet-open', open && isMobile());
    }

    function resetPanelPosition(details) {
        const panel = details?.querySelector('[data-stock-popover-panel]');
        if (!panel) return;
        panel.style.left = '';
        panel.style.right = '';
        panel.style.top = '';
        panel.style.bottom = '';
    }

    function positionPopover(details) {
        if (!details || isMobile()) {
            resetPanelPosition(details);
            return;
        }
        const trigger = details.querySelector('[data-stock-popover-trigger]');
        const panel = details.querySelector('[data-stock-popover-panel]');
        if (!trigger || !panel) return;
        const rect = trigger.getBoundingClientRect();
        const width = Math.min(340, window.innerWidth - 40);
        const left = Math.max(20, Math.min(window.innerWidth - width - 20, rect.right - width));
        const top = Math.min(window.innerHeight - 80, rect.bottom + 8);
        panel.style.left = left + 'px';
        panel.style.right = 'auto';
        panel.style.top = top + 'px';
        panel.style.bottom = 'auto';
    }

    function pushSheetHistory() {
        if (!isMobile() || sheetHistoryOpen) return;
        try {
            history.pushState({ stockSheet: true }, '', window.location.href);
            sheetHistoryOpen = true;
        } catch (error) {
            sheetHistoryOpen = false;
        }
    }

    function closePopover(details = activePopover, options = {}) {
        if (!details) return;
        details.open = false;
        details.dataset.mode = details.dataset.mode || 'more';
        details.querySelector('[data-stock-popover-trigger]')?.setAttribute('aria-expanded', 'false');
        resetPanelPosition(details);
        if (activePopover === details) activePopover = null;
        if (!activeFilterSheet) setBackdrop(false);
        if (sheetHistoryOpen && !options.fromPopState && isMobile()) {
            sheetHistoryOpen = false;
            try { history.back(); } catch (error) {}
        }
    }

    function setPopoverMode(details, mode) {
        const normalizedMode = ['view', 'timeline', 'more'].includes(mode) ? mode : 'more';
        details.dataset.mode = normalizedMode;
        const title = details.querySelector('[data-detail-title]');
        if (title) {
            title.textContent = normalizedMode === 'view'
                ? 'Movement Summary'
                : (normalizedMode === 'timeline' ? 'Movement Timeline' : 'Related Details');
        }
    }

    function openPopover(details, mode = 'more') {
        if (!details) return;
        if (activePopover && activePopover !== details) closePopover(activePopover, { skipHistory: true });
        setPopoverMode(details, mode);
        details.open = true;
        activePopover = details;
        details.querySelector('[data-stock-popover-trigger]')?.setAttribute('aria-expanded', 'true');
        positionPopover(details);
        if (isMobile()) {
            setBackdrop(true);
            pushSheetHistory();
        }
    }

    function togglePopover(details, mode = 'more') {
        if (!details) return;
        const normalizedMode = ['view', 'timeline', 'more'].includes(mode) ? mode : 'more';
        if (details.open && details.dataset.mode === normalizedMode) {
            closePopover(details);
        } else {
            openPopover(details, normalizedMode);
        }
    }

    function closeFilterSheet(details = activeFilterSheet) {
        if (!details) return;
        details.open = false;
        activeFilterSheet = null;
        if (!activePopover) setBackdrop(false);
    }

    document.addEventListener('click', function (event) {
        const popoverTrigger = event.target.closest('[data-stock-popover-trigger], [data-stock-open-details]');
        if (popoverTrigger) {
            event.preventDefault();
            event.stopPropagation();
            const row = popoverTrigger.closest('[data-stock-movement-row]');
            const details = row?.querySelector('[data-stock-popover]');
            const mode = popoverTrigger.getAttribute('data-stock-open-details') || 'more';
            togglePopover(details, mode);
            return;
        }

        const filterSummary = event.target.closest('#stock-history-filters details > summary');
        if (filterSummary && isMobile()) {
            const details = filterSummary.closest('details');
            window.setTimeout(function () {
                activeFilterSheet = details.open ? details : null;
                setBackdrop(Boolean(activeFilterSheet || activePopover));
            }, 0);
            return;
        }

        if (event.target.closest('[data-stock-popover-panel]') || event.target.closest('.stock-filters-panel')) {
            return;
        }

        if (activePopover) closePopover(activePopover);
        if (activeFilterSheet) closeFilterSheet(activeFilterSheet);
    }, true);

    backdrop.addEventListener('click', function () {
        if (activePopover) closePopover(activePopover);
        if (activeFilterSheet) closeFilterSheet(activeFilterSheet);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (activePopover) {
            event.preventDefault();
            closePopover(activePopover);
        }
        if (activeFilterSheet) {
            event.preventDefault();
            closeFilterSheet(activeFilterSheet);
        }
    });

    ['scroll', 'resize'].forEach(function (eventName) {
        window.addEventListener(eventName, function () {
            if (activePopover) positionPopover(activePopover);
        }, { passive: true });
    });

    mobileQuery.addEventListener?.('change', function () {
        if (activePopover) positionPopover(activePopover);
        setBackdrop(Boolean((activePopover || activeFilterSheet) && isMobile()));
    });

    document.querySelectorAll('[data-stock-popover-trigger]').forEach(function (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-haspopup', 'dialog');
    });

    document.querySelectorAll('[data-stock-popover-panel]').forEach(function (panel) {
        let startY = null;
        panel.addEventListener('pointerdown', function (event) {
            if (!isMobile()) return;
            startY = event.clientY;
        });
        panel.addEventListener('pointerup', function (event) {
            if (!isMobile() || startY === null) return;
            const delta = event.clientY - startY;
            startY = null;
            if (delta > 70 && activePopover) closePopover(activePopover);
        });
    });

    window.addEventListener('popstate', function () {
        if (activePopover && isMobile()) {
            sheetHistoryOpen = false;
            closePopover(activePopover, { fromPopState: true });
        }
    });
});
</script>
@endsection
