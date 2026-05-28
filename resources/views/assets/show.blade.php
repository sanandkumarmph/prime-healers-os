@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $canDeleteAssets = $currentUser?->canAccessModule('assets', 'delete') ?? false;
    $canViewAssetStockHistory = $currentUser?->hasAnyPermission(['stock_history.view', 'stock_history.asset']) ?? false;
    $rupee = html_entity_decode('&#8377;');
    $statusBadge = fn ($status) => match($status) {
        'available' => ['#ecfdf5', '#166534'],
        'awaiting_verification' => ['#fef3c7', '#b45309'],
        'rented' => ['#eff6ff', '#1d4ed8'],
        'maintenance' => ['#fff7ed', '#c2410c'],
        'reserved' => ['#f5f3ff', '#6d28d9'],
        'retired' => ['#f8fafc', '#475569'],
        'available_for_sale' => ['#fff7ed', '#9a3412'],
        'reserved_for_sale' => ['#fef3c7', '#b45309'],
        'sold' => ['#f3f4f6', '#4b5563'],
        'converted_to_rental' => ['#ecfeff', '#0f766e'],
        default => ['#f8fafc', '#334155'],
    };
    $badge = $statusBadge($asset->asset_status);
    $isNewStock = $asset->asset_stage === 'new_stock';
    $unitLabel = $isNewStock ? 'Sale Unit' : 'Rental Asset';
    $workflowControl = $workflowControl ?? ['locked' => false, 'message' => null, 'action_label' => null, 'action_url' => null, 'convert_url' => null, 'active_rental' => null, 'active_sale' => null];
    $activeRental = $workflowControl['active_rental']
        ?? $asset->activeRentalAssignments->sortByDesc('assigned_at')->first()?->rental
        ?? $asset->rentalAssignments->sortByDesc('assigned_at')->first()?->rental;
    $activeSale = $workflowControl['active_sale'] ?? $asset->sales->sortByDesc(fn ($sale) => $sale->sale_date ?? $sale->created_at)->first();
    $activeCustomer = $activeRental?->customer ?? $activeSale?->customer;
    $conversionHint = str_contains(strtolower((string) $asset->notes), 'converted from sale inventory')
        ? 'Converted from sale units'
        : null;
    $productName = optional($asset->product)->name ?: 'No linked product';
    $productIdentitySecondary = trim(collect([optional($asset->product)->brand, optional($asset->product)->model_name])->filter()->implode(' '));
    if ($productIdentitySecondary === '') {
        $productIdentitySecondary = trim((string) (optional($asset->product)->product_code ?: optional($asset->product)->sku ?: ''));
    }
    if ($productIdentitySecondary === '') {
        $productIdentitySecondary = 'No model assigned';
    }
@endphp

@section('content')
    <style>
        .asset-detail-page {
            display: grid;
            gap: 18px;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: clip;
            padding-bottom: calc(136px + env(safe-area-inset-bottom, 0px));
        }
        .asset-detail-page > * { min-width: 0; max-width: 100%; }
        .asset-detail-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            padding: 22px;
            min-width: 0;
            max-width: 100%;
        }
        .asset-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            min-width: 0;
        }
        .asset-detail-header > div { min-width: 0; max-width: 100%; }
        .asset-detail-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            min-width: 0;
            max-width: 100%;
        }
        .asset-detail-actions a,
        .asset-detail-actions button {
            min-width: 0;
            max-width: 100%;
            white-space: normal;
            text-align: center;
            line-height: 1.35;
        }
        .asset-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            min-width: 0;
        }
        .asset-detail-info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
            min-width: 0;
        }
        .asset-detail-info-item {
            padding: 14px 16px;
            border-radius: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            min-width: 0;
        }
        .asset-copy-row {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            margin-top: 6px;
        }
        .asset-copy-value {
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .asset-copy-btn {
            flex: 0 0 auto;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }
        .asset-detail-wrap-safe,
        .asset-detail-card table,
        .asset-detail-card tbody,
        .asset-detail-card tr,
        .asset-detail-card td,
        .asset-detail-card th {
            min-width: 0;
            max-width: 100%;
        }
        @media (max-width: 767px) {
            .asset-detail-page {
                gap: 12px;
                padding-bottom: calc(188px + env(safe-area-inset-bottom, 0px));
            }
            .asset-detail-card {
                padding: 16px;
                border-radius: 18px;
                overflow: hidden;
            }
            .asset-detail-header h1 {
                font-size: 24px !important;
                line-height: 1.15 !important;
            }
            .asset-detail-actions {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .asset-detail-actions a,
            .asset-detail-actions button {
                width: 100%;
                min-height: 40px !important;
                padding: 8px 12px !important;
                font-size: 12px !important;
            }
            .asset-detail-grid,
            .asset-detail-info-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .asset-detail-info-item {
                padding: 12px 13px;
            }
            .asset-copy-row {
                align-items:flex-start;
                flex-wrap:wrap;
            }
            .asset-copy-value {
                font-size: 13px !important;
                white-space:normal;
                overflow:visible;
                text-overflow:clip;
                overflow-wrap:anywhere;
                word-break:break-word;
            }
            .asset-detail-card div,
            .asset-detail-card span,
            .asset-detail-card p,
            .asset-detail-card strong,
            .asset-detail-card a,
            .asset-detail-card td,
            .asset-detail-card th {
                overflow-wrap:anywhere;
                word-break:break-word;
            }
            .asset-detail-card table,
            .asset-detail-card thead,
            .asset-detail-card tbody,
            .asset-detail-card tr,
            .asset-detail-card td {
                display:block;
                width:100%;
            }
            .asset-detail-card thead {
                display:none;
            }
            .asset-detail-card tbody {
                display:grid;
                gap:10px;
            }
            .asset-detail-card tr {
                border:1px solid #e2e8f0;
                border-radius:14px;
                background:#f8fafc;
                padding:10px 12px;
            }
            .asset-detail-card td {
                padding:0 !important;
                border:none !important;
            }
            .asset-detail-card td + td {
                margin-top:8px;
            }
            .asset-detail-card td::before {
                content:attr(data-label);
                display:block;
                margin-bottom:4px;
                color:#64748b;
                font-size:10px;
                font-weight:800;
                letter-spacing:.08em;
                text-transform:uppercase;
            }
        }
    </style>

    <div class="asset-detail-page">
        <div class="asset-detail-card">
            <div class="asset-detail-header">
                <div>
                    <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $isNewStock ? '#fff7ed' : '#eff6ff' }}; color:{{ $isNewStock ? '#9a3412' : '#1d4ed8' }}; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">
                        {{ $unitLabel }}
                    </div>
                    <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em; word-break:normal; overflow-wrap:anywhere;">{{ $productName }}</h1>
                    <p style="margin:0; color:#64748b; word-break:normal; overflow-wrap:anywhere;">{{ $productIdentitySecondary }}</p>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $isNewStock ? '#fff7ed' : '#eff6ff' }}; color:{{ $isNewStock ? '#9a3412' : '#1d4ed8' }}; font-size:11px; font-weight:700; text-transform:uppercase;">{{ $unitLabel }}</span>
                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:11px; font-weight:700; text-transform:uppercase;">{{ str_replace('_', ' ', $asset->asset_status) }}</span>
                        @if($asset->isSerialPending())
                            <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Serial Pending</span>
                        @endif
                    </div>
                </div>

                <div class="asset-detail-actions page-header-actions">
                    <a href="{{ route('assets.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Back to Asset Register</a>
                    @if($canViewAssetStockHistory)
                        <a href="{{ route('stock-history.index', ['asset_id' => $asset->id]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; text-decoration:none; font-weight:700;">Stock History</a>
                    @endif
                    @if($canUpdateAssets)
                        @if(!empty($workflowControl['action_label']) && !empty($workflowControl['action_url']))
                            <a href="{{ $workflowControl['action_url'] }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#fef3c7; color:#b45309; text-decoration:none; font-weight:800;">{{ $workflowControl['action_label'] }}</a>
                        @elseif(!empty($workflowControl['convert_url']))
                            <a href="{{ $workflowControl['convert_url'] }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#ecfeff; color:#0f766e; text-decoration:none; font-weight:700;">Convert Stock</a>
                        @endif
                        <a href="{{ route('assets.transfer', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#ecfeff; color:#0f766e; text-decoration:none; font-weight:700;">Transfer Warehouse</a>
                        <a href="{{ route('assets.edit', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">Edit Metadata</a>
                    @endif
                </div>
            </div>
        </div>

        @if(session('success'))
            <div style="padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
        @endif

        @if(!empty($workflowControl['locked']) && !empty($workflowControl['message']))
            <div style="padding:14px 16px; border-radius:16px; background:#fffbeb; border:1px solid #fde68a; color:#92400e;">
                {{ $workflowControl['message'] }}
            </div>
        @endif

        <div class="asset-detail-grid">
            <div class="asset-detail-card">
                <h2 style="margin:0;">Identity</h2>
                <p style="margin:8px 0 0; color:#64748b;">Core reference details for this physical unit.</p>

                <div class="asset-detail-info-grid">
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Serial Number</div>
                        <div class="asset-copy-row">
                            <div class="asset-copy-value" title="{{ $asset->serial_number }}">{{ $asset->serial_number }}</div>
                            <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                        </div>
                        @if($asset->isSerialPending())
                            <div style="margin-top:8px; color:#9a3412; font-size:12px; font-weight:700;">Temporary placeholder serial. Update this when the real unit serial is confirmed.</div>
                        @endif
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Barcode</div>
                        @if($asset->barcode_value)
                            <div class="asset-copy-row">
                                <div class="asset-copy-value" title="{{ $asset->barcode_value }}">{{ $asset->barcode_value }}</div>
                                <button type="button" class="asset-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                            </div>
                        @else
                            <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">N/A</div>
                        @endif
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Batch Number</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ $asset->batch_number ?: 'N/A' }}</div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Warehouse</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Purchase Date</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ optional($asset->purchase_date)->format('d M Y') ?: 'N/A' }}</div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Purchase Cost</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ $asset->purchase_cost !== null ? $rupee . ' ' . number_format($asset->purchase_cost, 2) : 'N/A' }}</div>
                    </div>
                </div>
            </div>

            <div class="asset-detail-card">
                <h2 style="margin:0;">Operational Status</h2>
                <p style="margin:8px 0 0; color:#64748b;">Lifecycle state and workflow position for this unit.</p>

                <div class="asset-detail-info-grid">
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Unit Type</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ $unitLabel }}</div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Status</div>
                        <div style="margin-top:10px;">
                            <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:12px; font-weight:700; text-transform:uppercase;">{{ str_replace('_', ' ', $asset->asset_status) }}</span>
                        </div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Condition</div>
                        <div style="margin-top:6px; font-size:16px; font-weight:700; text-transform:capitalize; color:#0f172a;">{{ $isNewStock ? 'Fresh stock' : ($asset->condition_status ?: 'Not recorded') }}</div>
                    </div>
                    <div class="asset-detail-info-item">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Workflow</div>
                        <div style="margin-top:6px; color:#0f172a; font-weight:700;">
                            @if($asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                                Waiting for return verification
                            @elseif($asset->asset_status === \App\Models\Asset::STATUS_RENTED)
                                With customer
                            @elseif($asset->asset_status === \App\Models\Asset::STATUS_SOLD)
                                Sold through sale workflow
                            @elseif($asset->asset_status === \App\Models\Asset::STATUS_MAINTENANCE)
                                Under repair / maintenance
                            @else
                                Available for the next workflow step
                            @endif
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px; padding:16px; border-radius:18px; background:{{ $isNewStock ? '#fffaf0' : '#eff6ff' }}; border:1px solid {{ $isNewStock ? '#fed7aa' : '#bfdbfe' }};">
                    <div style="font-size:11px; color:{{ $isNewStock ? '#9a3412' : '#1d4ed8' }}; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Operational Note</div>
                    <div style="margin-top:8px; color:{{ $isNewStock ? '#9a3412' : '#1e3a8a' }}; line-height:1.6;">
                        @if($isNewStock)
                            Fresh physical unit currently treated as a sale unit. It may later be sold directly or converted into rental assets.
                        @else
                            {{ $conversionHint ?: 'Operational rental unit intended for dispatch, pickup, maintenance, and lifecycle tracking.' }}
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="asset-detail-grid">
            <div class="asset-detail-card">
                <h2 style="margin:0;">Current Link</h2>
                <p style="margin:8px 0 0; color:#64748b;">Only shown when this unit is currently tied to a rental, sale, or verification step.</p>

                @if($activeRental || $activeSale || $asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                    <div class="asset-detail-info-grid">
                        @if($activeRental)
                            <div class="asset-detail-info-item">
                                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Rental</div>
                                <div style="margin-top:6px; font-size:16px; font-weight:700;">
                                    <a href="{{ route('rentals.show', $activeRental) }}" style="color:#1d4ed8; text-decoration:none;">Rental #{{ $activeRental->id }}</a>
                                </div>
                            </div>
                        @endif
                        @if($activeSale)
                            <div class="asset-detail-info-item">
                                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Sale</div>
                                <div style="margin-top:6px; font-size:16px; font-weight:700;">
                                    <a href="{{ route('sales.show', $activeSale) }}" style="color:#1d4ed8; text-decoration:none;">Sale #{{ $activeSale->id }}</a>
                                </div>
                            </div>
                        @endif
                        @if($activeCustomer)
                            <div class="asset-detail-info-item">
                                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Customer</div>
                                <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ $activeCustomer->name }}</div>
                            </div>
                        @endif
                        @if($asset->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                            <div class="asset-detail-info-item">
                                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Return Verification</div>
                                <div style="margin-top:10px;">
                                    <a href="{{ route('assets.verify-return', $asset) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#fef3c7; color:#b45309; text-decoration:none; font-weight:800;">Verify Return</a>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div style="margin-top:18px; padding:16px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                        This unit is not currently linked to an active rental, active sale, or return verification step.
                    </div>
                @endif
            </div>

            <div class="asset-detail-card">
                <h2 style="margin:0;">Notes &amp; Movement History</h2>
                <p style="margin:8px 0 0; color:#64748b;">Notes, warehouse changes, and operational trace for this physical unit.</p>

                <div style="margin-top:18px; padding:16px; border-radius:18px; background:#ffffff; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Notes</div>
                    <div style="margin-top:8px; color:#475569; line-height:1.7;">{{ $asset->notes ?: 'No notes added.' }}</div>
                </div>

                <div class="responsive-table-shell" style="margin-top:18px;">
                    <div class="responsive-table-scroll">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead style="background:#f8fafc;">
                            <tr>
                                <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Date</th>
                                <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Type</th>
                                <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">From</th>
                                <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">To</th>
                                <th style="text-align:left; padding:14px 18px; font-size:12px; color:#64748b; text-transform:uppercase;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($asset->movements as $movement)
                            <tr style="border-top:1px solid #e2e8f0;">
                                <td data-label="Date" style="padding:16px 18px;">{{ $movement->created_at->format('d M Y, h:i A') }}</td>
                                <td data-label="Type" style="padding:16px 18px; text-transform:capitalize;">{{ $movement->movement_type }}</td>
                                <td data-label="From" style="padding:16px 18px;">{{ optional($movement->fromWarehouse)->name ?: 'N/A' }}</td>
                                <td data-label="To" style="padding:16px 18px;">{{ optional($movement->toWarehouse)->name ?: 'N/A' }}</td>
                                <td data-label="Remarks" style="padding:16px 18px;">{{ $movement->remarks ?: 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="padding:22px 18px; color:#64748b;">No movement history available yet.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        @if($canDeleteAssets)
            <div class="asset-detail-card">
                <h2 style="margin:0;">Actions</h2>
                <p style="margin:8px 0 0; color:#64748b;">Delete remains available here when dependencies allow it.</p>
                <div style="margin-top:18px;">
                    <form method="POST" action="{{ route('assets.destroy', $asset) }}" onsubmit="return confirm('Delete this asset? This will be blocked if dependencies exist.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:none; border-radius:12px; background:#fff1f2; color:#be123c; font-weight:700; cursor:pointer;">
                            Delete Asset
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const copyButtons = document.querySelectorAll('[data-copy-text]');
    if (!copyButtons.length) {
        return;
    }

    copyButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const originalLabel = button.textContent;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(button.dataset.copyText || '');
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = button.dataset.copyText || '';
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                }
                button.textContent = 'Copied';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 1400);
            } catch (_error) {
                button.textContent = 'Failed';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 1400);
            }
        });
    });
})();
</script>
@endpush
