@extends('layouts.app')

@section('content')
    @php
        $selectedStage = old('asset_stage', $asset->asset_stage ?: \App\Models\Asset::STAGE_RENTAL_STOCK);
    @endphp

    <style>
        .add-stock-entry {
            max-width: 1080px;
            margin: 0 auto 18px;
            display: grid;
            gap: 16px;
        }
        .add-stock-entry-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .add-stock-entry-card {
            display: grid;
            gap: 8px;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            text-decoration: none;
            color: inherit;
        }
        .add-stock-entry-card.is-active {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }
        @media (max-width: 767px) {
            .add-stock-entry {
                gap: 12px;
                margin-bottom: 14px;
            }
            .add-stock-entry-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .add-stock-entry-card {
                padding: 14px;
                border-radius: 16px;
            }
        }
    </style>

    <div class="add-stock-entry">
        <div style="padding:16px 18px; border-radius:20px; border:1px solid #e2e8f0; background:#ffffff;">
            <div style="font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Add Stock</div>
            <div style="margin-top:8px; font-size:15px; font-weight:700; color:#0f172a;">Product Master stores catalog and pricing. Asset Register stores physical units. Add Stock creates one physical unit at a time.</div>
        </div>

        <div class="add-stock-entry-grid">
            <a href="{{ route('assets.create', ['asset_stage' => 'new_stock', 'product_id' => request('product_id', $asset->product_id)]) }}" class="add-stock-entry-card{{ $selectedStage === \App\Models\Asset::STAGE_NEW_STOCK ? ' is-active' : '' }}">
                <div style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Sale Unit</div>
                <div style="font-size:18px; font-weight:800; color:#0f172a;">Add Sale Unit</div>
                <div style="font-size:13px; color:#64748b; line-height:1.55;">Use this when you are adding a fresh physical unit that should be available for sale first.</div>
            </a>
            <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock', 'product_id' => request('product_id', $asset->product_id)]) }}" class="add-stock-entry-card{{ $selectedStage === \App\Models\Asset::STAGE_RENTAL_STOCK ? ' is-active' : '' }}">
                <div style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">Rental Asset</div>
                <div style="font-size:18px; font-weight:800; color:#0f172a;">Add Rental Asset</div>
                <div style="font-size:13px; color:#64748b; line-height:1.55;">Use this when you are adding a physical unit that should enter rental dispatch, pickup, and verification workflows.</div>
            </a>
        </div>
    </div>

    @include('assets._form', ['asset' => $asset, 'products' => $products, 'warehouses' => $warehouses, 'assetStatuses' => $assetStatuses, 'conditionStatuses' => $conditionStatuses, 'prefillLookup' => $prefillLookup ?? ''])
@endsection
