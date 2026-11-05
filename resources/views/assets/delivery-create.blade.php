@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head">
        <div><div class="sw-eyebrow">Delivery Operations</div><h1>Add an asset</h1></div>
        <a class="sw-btn" href="{{ route('deliveries.index') }}">Task Board</a>
    </header>
    @if(session('success'))<p class="sw-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="sw-error" role="alert">{{ $errors->first() }}</p>@endif
    <form class="sw-panel" method="post" action="{{ route('deliveries.assets.store') }}">
        @csrf
        <div class="sw-fields">
            <div class="sw-field">
                <label for="product_id">Product</label>
                <select id="product_id" name="product_id" required>
                    <option value="">Choose product</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sw-field">
                <label for="warehouse_id">Warehouse</label>
                <select id="warehouse_id" name="warehouse_id" required>
                    <option value="">Choose warehouse</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sw-field">
                <label for="asset_stage">Stock type</label>
                <select id="asset_stage" name="asset_stage" required>
                    @foreach([\App\Models\Asset::STAGE_RENTAL_STOCK => 'Rental stock', \App\Models\Asset::STAGE_NEW_STOCK => 'New sale stock'] as $stage => $label)
                        <option value="{{ $stage }}" @selected(old('asset_stage') === $stage)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sw-field"><label for="serial_number">Serial number</label><input name="serial_number" id="serial_number" maxlength="255" value="{{ old('serial_number') }}"></div>
            <div class="sw-field"><label for="barcode_value">Barcode</label><input name="barcode_value" id="barcode_value" maxlength="255" value="{{ old('barcode_value') }}"></div>
            <div class="sw-field"><label for="batch_number">Batch number</label><input name="batch_number" id="batch_number" maxlength="255" value="{{ old('batch_number') }}"></div>
            <div class="sw-field"><label for="notes">Notes</label><textarea name="notes" id="notes">{{ old('notes') }}</textarea></div>
        </div>
        <p><label><input type="checkbox" name="serial_pending" value="1" @checked(old('serial_pending'))> Serial number unavailable</label></p>
        <button class="sw-btn sw-primary">Add asset</button>
    </form>
</main>
@endsection
