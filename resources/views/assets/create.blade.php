@extends('layouts.app')

@section('content')
    @include('assets._form', ['asset' => $asset, 'products' => $products, 'warehouses' => $warehouses, 'assetStatuses' => $assetStatuses, 'conditionStatuses' => $conditionStatuses, 'prefillLookup' => $prefillLookup ?? ''])
@endsection
