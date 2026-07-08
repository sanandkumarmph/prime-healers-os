@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canUpdateAssets = $currentUser?->canAccessModule('assets', 'update') ?? false;
    $statusBadge = fn ($status) => match($status) {
        'awaiting_verification' => ['#fef3c7', '#b45309'],
        'available' => ['#ecfdf5', '#166534'],
        'maintenance' => ['#fff7ed', '#c2410c'],
        'retired' => ['#f8fafc', '#475569'],
        default => ['#f8fafc', '#334155'],
    };
@endphp

@section('content')
    <style>
        .rv-page { display:grid; gap:10px; padding:6px 0 18px; }
        .rv-header {
            display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;
            padding:12px 16px; border:1px solid #dbe3ef; border-radius:18px; background:#fff;
            box-shadow:0 10px 26px rgba(15,23,42,.05);
        }
        .rv-header h1 { margin:4px 0 0; color:#0f172a; font-size:24px; line-height:1.05; }
        .rv-header p { margin:4px 0 0; color:#64748b; font-size:12px; }
        .rv-eyebrow {
            display:inline-flex; align-items:center; width:max-content; padding:5px 10px; border-radius:999px;
            background:#fef3c7; color:#b45309; font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.08em;
        }
        .rv-kpi-strip { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:8px; }
        .rv-kpi {
            display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px;
            border:1px solid #fde68a; border-radius:14px; background:#fffbeb; min-width:0;
        }
        .rv-kpi span { color:#92400e; font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.06em; }
        .rv-kpi small { display:block; margin-top:1px; color:#a16207; font-size:11px; }
        .rv-kpi strong { color:#b45309; font-size:24px; line-height:1; }
        .rv-panel { border:1px solid #dbe3ef; border-radius:18px; background:#fff; overflow:hidden; box-shadow:0 10px 24px rgba(15,23,42,.04); min-width:0; }
        .rv-panel-head {
            display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
            padding:12px 14px; border-bottom:1px solid #e5edf6;
        }
        .rv-panel-head h2 { margin:0; color:#0f172a; font-size:18px; }
        .rv-panel-head p { margin:3px 0 0; color:#64748b; font-size:12px; }
        .rv-list {
            display:block;
            overflow-x:auto;
            overflow-y:visible;
            -webkit-overflow-scrolling:touch;
            scrollbar-width:thin;
            scrollbar-color:#cbd5e1 transparent;
        }
        .rv-list::-webkit-scrollbar { height:8px; }
        .rv-list::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
        .rv-list::-webkit-scrollbar-track { background:transparent; }
        .rv-row {
            display:grid; grid-template-columns:42px minmax(210px,1.1fr) minmax(180px,.85fr) minmax(175px,.8fr) minmax(155px,.7fr) minmax(188px,188px);
            gap:10px; align-items:center; width:max(100%, 1060px); box-sizing:border-box; padding:11px 14px; border-top:1px solid #edf2f7; background:#fff;
        }
        .rv-row:first-child { border-top:0; }
        .rv-row:hover { background:#f8fbff; }
        .rv-number {
            width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px;
            background:#eef2ff; color:#1d4ed8; font-size:12px; font-weight:900;
        }
        .rv-main, .rv-cell { display:grid; gap:3px; min-width:0; }
        .rv-title { color:#0f172a; font-size:14px; font-weight:900; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rv-subtle { color:#64748b; font-size:11.5px; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rv-label { color:#64748b; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.06em; }
        .rv-value { color:#0f172a; font-size:12.5px; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rv-link { color:#1d4ed8; text-decoration:none; font-weight:900; }
        .rv-badges { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .rv-badge {
            display:inline-flex; align-items:center; width:max-content; max-width:100%; padding:5px 8px; border-radius:999px;
            font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.03em; white-space:nowrap;
        }
        .rv-badge-warning { background:#fff7ed; color:#9a3412; }
        .rv-actions { display:grid; grid-template-columns:1fr 1.1fr; align-items:center; justify-content:stretch; gap:7px; min-width:0; }
        .rv-btn, .rv-btn-soft {
            display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:8px 10px;
            border-radius:12px; font-size:12px; font-weight:900; text-decoration:none; white-space:nowrap;
        }
        .rv-btn { background:#4f46e5; color:#fff !important; box-shadow:0 12px 22px rgba(79,70,229,.2); }
        .rv-btn-soft { border:1px solid #cbd5e1; color:#334155; background:#fff; }
        .rv-empty { margin:14px; padding:18px; border-radius:16px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569; font-size:13px; }
        .rv-pagination { padding:12px 14px; border-top:1px solid #edf2f7; }
        @media (max-width: 1180px) {
            .rv-row { grid-template-columns:36px minmax(210px,1fr) minmax(170px,.85fr) minmax(160px,.8fr) minmax(180px,180px); width:max(100%, 860px); }
            .rv-cell-location { display:none; }
        }
        @media (max-width: 767px) {
            .rv-header { padding:12px; border-radius:16px; }
            .rv-header h1 { font-size:22px; }
            .rv-kpi-strip { grid-template-columns:1fr; }
            .rv-row {
                grid-template-columns:34px minmax(0,1fr);
                width:auto;
                gap:8px;
                align-items:start;
                margin:10px;
                padding:12px;
                border:1px solid #dbe3ef;
                border-radius:16px;
                background:#fff;
                box-shadow:0 8px 20px rgba(15,23,42,.05);
            }
            .rv-row:first-child { border-top:1px solid #dbe3ef; }
            .rv-main { grid-column:2; }
            .rv-cell, .rv-cell-location, .rv-actions { grid-column:1 / -1; display:grid; justify-content:stretch; }
            .rv-cell {
                padding:9px 10px;
                border-radius:12px;
                background:#f8fafc;
                border:1px solid #edf2f7;
            }
            .rv-actions { grid-template-columns:1fr; }
            .rv-actions .rv-btn,
            .rv-actions .rv-btn-soft { width:100%; }
            .rv-title, .rv-subtle, .rv-value { white-space:normal; }
            .rv-panel-head { padding:14px; }
        }
    </style>

    <div class="rv-page">
        <div class="rv-header">
            <div>
                <span class="rv-eyebrow">Return Verification</span>
                <h1>Return Verification</h1>
                <p>Confirm returned rental units, capture missing serials, and route them back to stock or repair.</p>
            </div>
            <a href="{{ route('assets.index') }}" class="rv-btn-soft">Back to Asset Register</a>
        </div>

        @if(session('success'))
            <div style="padding:10px 12px; border-radius:14px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; font-size:13px;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="padding:10px 12px; border-radius:14px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b; font-size:13px;">{{ session('error') }}</div>
        @endif

        <div class="rv-kpi-strip">
            <div class="rv-kpi">
                <div><span>Queue Size</span><small>Assets needing verification</small></div>
                <strong>{{ $assets->total() }}</strong>
            </div>
            <div class="rv-kpi">
                <div><span>Serial Checks</span><small>Capture missing serial/barcode before routing</small></div>
                <strong>{{ $assets->getCollection()->filter(fn ($asset) => $asset->isSerialPending() || blank($asset->serial_number))->count() }}</strong>
            </div>
            <div class="rv-kpi">
                <div><span>Ready Action</span><small>Verify, repair, retire, or return to available stock</small></div>
                <strong>{{ $assets->count() }}</strong>
            </div>
        </div>

        <section class="rv-panel">
            <div class="rv-panel-head">
                <div>
                    <h2>Verification Queue</h2>
                    <p>Action first list with product, serial, rental, pickup, and location context.</p>
                </div>
            </div>

            @if($assets->count() === 0)
                <div class="rv-empty">No returned rental assets are waiting for verification right now.</div>
            @else
                <div class="rv-list">
                    @foreach($assets as $asset)
                        @php
                            $badge = $statusBadge($asset->asset_status);
                            $activeAssignment = $asset->activeRentalAssignments->sortByDesc('assigned_at')->first();
                            $latestAssignment = $asset->rentalAssignments->sortByDesc('assigned_at')->first();
                            $activeRental = $activeAssignment?->rental ?? $latestAssignment?->rental;
                            $activeCustomer = $activeRental?->customer;
                            $pickupDate = $latestAssignment?->returned_at ?: $latestAssignment?->updated_at;
                            $rowNumber = method_exists($assets, 'firstItem') && $assets->firstItem()
                                ? $assets->firstItem() + $loop->index
                                : $loop->iteration;
                        @endphp
                        <article class="rv-row">
                            <div class="rv-number">{{ $rowNumber }}</div>

                            <div class="rv-main">
                                <div class="rv-title">{{ optional($asset->product)->name ?: 'No linked product' }}</div>
                                <div class="rv-subtle">{{ $asset->asset_name ?: 'Unit #' . $asset->id }}</div>
                                <div class="rv-badges">
                                    <span class="rv-badge" style="background:{{ $badge[0] }}; color:{{ $badge[1] }};">{{ str_replace('_', ' ', $asset->asset_status) }}</span>
                                    @if($asset->isSerialPending())
                                        <span class="rv-badge rv-badge-warning">Serial pending</span>
                                    @endif
                                </div>
                            </div>

                            <div class="rv-cell">
                                <span class="rv-label">Serial / Barcode</span>
                                <span class="rv-value">S/N: {{ $asset->serial_number ?: 'Capture now' }}</span>
                                <span class="rv-subtle">Barcode: {{ $asset->barcode_value ?: 'Optional' }}</span>
                            </div>

                            <div class="rv-cell">
                                <span class="rv-label">Rental / Customer</span>
                                <span class="rv-value">
                                    @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                        <a href="{{ route('rentals.show', $activeRental) }}" class="rv-link">Rental #{{ $activeRental->id }}</a>
                                    @else
                                        {{ $activeRental ? 'Rental #' . $activeRental->id : 'Not linked' }}
                                    @endif
                                </span>
                                <span class="rv-subtle">{{ $activeCustomer?->name ?: 'Customer not linked' }}</span>
                            </div>

                            <div class="rv-cell rv-cell-location">
                                <span class="rv-label">Pickup / Warehouse</span>
                                <span class="rv-value">{{ optional($pickupDate)->format('d M Y, h:i A') ?: 'Recent pickup' }}</span>
                                <span class="rv-subtle">{{ optional($asset->warehouse)->name ?: 'Warehouse not set' }}</span>
                            </div>

                            <div class="rv-actions">
                                <a href="{{ route('assets.show', $asset) }}" class="rv-btn-soft">View Asset</a>
                                @if($canUpdateAssets)
                                    <a href="{{ route('assets.verify-return', $asset) }}" class="rv-btn">Verify Return</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="rv-pagination">
                    {{ $assets->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
