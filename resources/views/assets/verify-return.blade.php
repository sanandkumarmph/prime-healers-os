@extends('layouts.app')

@section('content')
    <style>
        @media (max-width: 767px) {
            .rn-detail-page {
                padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px));
            }
            .verify-return-summary-grid,
            .verify-return-form-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .verify-return-summary-card,
            .verify-return-form-actions {
                min-width: 0;
            }
            .verify-return-form-actions {
                justify-content: stretch !important;
            }
            .verify-return-form-actions > * {
                width: 100%;
            }
            .verify-return-form-actions .rx-btn-soft,
            .verify-return-form-actions .rx-btn-primary {
                justify-content: center;
            }
            .rn-detail-page div,
            .rn-detail-page span,
            .rn-detail-page p,
            .rn-detail-page strong,
            .rn-detail-page a,
            .rn-detail-page label {
                overflow-wrap:anywhere;
                word-break:break-word;
            }
        }
    </style>
    <div class="rn-detail-page">
        <div class="rx-page-header" style="margin-bottom:20px;">
            <div>
                <div class="rx-eyebrow" style="background:#fef3c7; color:#b45309;">Return Verification</div>
                <h1 class="rx-page-title">Verify Returned Asset</h1>
                <p class="rx-page-subtitle">Confirm the asset condition before returning it to stock, maintenance, or retirement.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('assets.pending-verification') }}" class="rx-btn-soft">Back to Verification Queue</a>
            </div>
        </div>

        @if ($errors->any())
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">
                <div style="font-weight:800; margin-bottom:8px;">Please fix the following:</div>
                <ul style="margin:0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rx-card" style="margin-bottom:18px; border-radius:22px;">
            <div class="rx-card-body" style="padding:20px 22px;">
                @if($asset->isSerialPending())
                    <div style="margin-bottom:16px; padding:14px 16px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
                        <strong>Serial Pending:</strong> this unit was created with a temporary placeholder serial. Enter the real serial number now if it is available before returning the unit to service.
                    </div>
                @endif
                <div class="verify-return-summary-grid" style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px;">
                    <div class="verify-return-summary-card" style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Product</div>
                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ optional($asset->product)->name ?: 'N/A' }}</div>
                    </div>
                    <div class="verify-return-summary-card" style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Warehouse</div>
                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                    </div>
                    <div class="verify-return-summary-card" style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Status</div>
                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#b45309; text-transform:capitalize;">{{ str_replace('_', ' ', $asset->asset_status) }}</div>
                    </div>
                    <div class="verify-return-summary-card" style="padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                        <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Current Condition</div>
                        <div style="margin-top:6px; font-size:15px; font-weight:700; color:#0f172a; text-transform:capitalize;">{{ $asset->condition_status ?: 'Not recorded' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rx-card" style="border-radius:22px;">
            <div class="rx-card-body" style="padding:22px;">
                <form method="POST" action="{{ route('assets.verify-return.store', $asset) }}" style="display:grid; gap:18px;">
                    @csrf
                    @method('PUT')

                    <div class="verify-return-form-grid" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                        <div>
                            <label for="serial_number" style="display:block; margin-bottom:8px; color:#334155; font-size:14px; font-weight:700;">Serial Number</label>
                            <input
                                type="text"
                                id="serial_number"
                                name="serial_number"
                                value="{{ old('serial_number', $asset->serial_number) }}"
                                required
                                style="width:100%; padding:13px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                            <p style="margin:8px 0 0; color:#64748b; font-size:12px;">Capture or correct the unique unit serial if it was missing earlier. Placeholder serials should be replaced here whenever the real serial is known.</p>
                        </div>

                        <div>
                            <label for="barcode_value" style="display:block; margin-bottom:8px; color:#334155; font-size:14px; font-weight:700;">Barcode</label>
                            <input
                                type="text"
                                id="barcode_value"
                                name="barcode_value"
                                value="{{ old('barcode_value', $asset->barcode_value) }}"
                                style="width:100%; padding:13px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                        </div>

                        <div>
                            <label for="manufacturing_year" style="display:block; margin-bottom:8px; color:#334155; font-size:14px; font-weight:700;">Manufacturing Year</label>
                            <input
                                type="number"
                                id="manufacturing_year"
                                name="manufacturing_year"
                                min="1900"
                                max="2100"
                                value="{{ old('manufacturing_year') }}"
                                style="width:100%; padding:13px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                        </div>

                        <div>
                            <label for="verification_outcome" style="display:block; margin-bottom:8px; color:#334155; font-size:14px; font-weight:700;">Verification Outcome</label>
                            <select
                                id="verification_outcome"
                                name="verification_outcome"
                                required
                                style="width:100%; padding:13px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                <option value="">Select outcome</option>
                                <option value="good" @selected(old('verification_outcome') === 'good')>Good -> Available</option>
                                <option value="repair" @selected(old('verification_outcome') === 'repair')>Repair -> Maintenance</option>
                                <option value="scrap" @selected(old('verification_outcome') === 'scrap')>Scrap -> Retired</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="remarks" style="display:block; margin-bottom:8px; color:#334155; font-size:14px; font-weight:700;">Remarks</label>
                        <textarea
                            id="remarks"
                            name="remarks"
                            rows="4"
                            style="width:100%; padding:13px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">{{ old('remarks') }}</textarea>
                    </div>

                    <div class="verify-return-form-actions" style="display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
                        <a href="{{ route('assets.pending-verification') }}" class="rx-btn-soft">Cancel</a>
                        <button type="submit" class="rx-btn-primary">Verify Asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
