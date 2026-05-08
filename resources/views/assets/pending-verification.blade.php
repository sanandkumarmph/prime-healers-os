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
        .verification-queue-list {
            display: grid;
            gap: 14px;
        }
        .verification-queue-card {
            padding: 18px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            display: grid;
            gap: 14px;
        }
        .verification-queue-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        @media (max-width: 767px) {
            .verification-queue-card {
                padding: 14px;
                border-radius: 16px;
            }
            .verification-queue-meta {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
    </style>
    <div class="rn-list-page">
        <div class="rx-page-header" style="margin-bottom:20px;">
            <div>
                <div class="rx-eyebrow" style="background:#fef3c7; color:#b45309;">Return Verification</div>
                <h1 class="rx-page-title">Return Verification</h1>
                <p class="rx-page-subtitle">Operations queue for tracked rental units waiting on post-return verification.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('assets.index') }}" class="rx-btn-soft">Back to Asset Register</a>
            </div>
        </div>

        @if(session('success'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">{{ session('error') }}</div>
        @endif

        <div class="rx-stat-grid" style="margin-bottom:18px;">
            <div style="display:block; padding:18px; border-radius:20px; background:#fef3c7; border:1px solid #fde68a;">
                <div style="font-size:11px; color:#b45309; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Queue Size</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:#b45309;">{{ $assets->total() }}</div>
            </div>
        </div>

        <div class="rx-card" style="border-radius:22px;">
            <div class="rx-card-body" style="padding:20px 22px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
                    <div>
                        <h2 style="margin:0; font-size:22px;">Return Verification Queue</h2>
                        <p style="margin:8px 0 0; color:#64748b;">Capture any missing serial or barcode details, then route each unit back to service, repair, or retirement.</p>
                    </div>
                </div>

                @if($assets->count() === 0)
                    <div style="padding:18px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                        No returned rental assets are waiting for verification right now.
                    </div>
                @else
                    <div class="verification-queue-list">
                        @foreach($assets as $asset)
                            @php
                                $badge = $statusBadge($asset->asset_status);
                                $activeAssignment = $asset->activeRentalAssignments->sortByDesc('assigned_at')->first();
                                $latestAssignment = $asset->rentalAssignments->sortByDesc('assigned_at')->first();
                                $activeRental = $activeAssignment?->rental ?? $latestAssignment?->rental;
                                $activeCustomer = $activeRental?->customer;
                                $pickupDate = $latestAssignment?->returned_at ?: $latestAssignment?->updated_at;
                            @endphp
                            <div class="verification-queue-card">
                                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
                                    <div style="display:grid; gap:6px;">
                                        <div style="font-size:18px; font-weight:800; color:#0f172a;">{{ optional($asset->product)->name ?: 'No linked product' }}</div>
                                        <div style="font-size:13px; color:#64748b;">{{ $asset->asset_name ?: 'Unit #' . $asset->id }}</div>
                                        @if($asset->isSerialPending())
                                            <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Serial Pending</span>
                                        @endif
                                    </div>
                                    <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:12px; font-weight:800; text-transform:uppercase;">
                                        {{ str_replace('_', ' ', $asset->asset_status) }}
                                    </span>
                                </div>

                                <div class="verification-queue-meta">
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Asset</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ $asset->asset_name ?: 'Unit #' . $asset->id }}</div>
                                    </div>
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Serial Number</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ $asset->serial_number ?: 'Capture now' }}</div>
                                    </div>
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Barcode</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ $asset->barcode_value ?: 'Optional' }}</div>
                                    </div>
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Warehouse</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                                    </div>
                                </div>

                                <div class="verification-queue-meta">
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Rental</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">
                                            @if($activeRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                                <a href="{{ route('rentals.show', $activeRental) }}" style="color:#1d4ed8; text-decoration:none;">Rental #{{ $activeRental->id }}</a>
                                            @else
                                                {{ $activeRental ? 'Rental #' . $activeRental->id : 'Not linked' }}
                                            @endif
                                        </div>
                                        @if($activeCustomer)
                                            <div style="margin-top:4px; font-size:12px; color:#64748b;">{{ $activeCustomer->name }}</div>
                                        @endif
                                    </div>
                                    <div style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Pickup Date</div>
                                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ optional($pickupDate)->format('d M Y, h:i A') ?: 'Recent pickup' }}</div>
                                    </div>
                                </div>

                                @if($asset->isSerialPending())
                                    <div style="padding:12px 14px; border-radius:14px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:13px; font-weight:700;">
                                        Serial Pending. Enter the real serial number during verification if it is now available.
                                    </div>
                                @endif

                                <div style="display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
                                    <a href="{{ route('assets.show', $asset) }}" class="rx-btn-soft">View Asset</a>
                                    @if($canUpdateAssets)
                                        <a href="{{ route('assets.verify-return', $asset) }}" class="rx-btn-primary">Verify Return</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div style="margin-top:18px;">
                        {{ $assets->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
