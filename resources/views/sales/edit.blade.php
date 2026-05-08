@extends('layouts.app')

@section('content')
<form action="{{ route('sales.update', $sale->id) }}" method="POST">
    @csrf
    @method('PUT')
    @include('sales.partials.form', ['sale' => $sale])
</form>

@include('customers.partials.quick-create-modal', [
    'quickModalId' => 'saleQuickCustomerModal',
    'quickFormId' => 'saleQuickCustomerForm',
    'quickSelectTarget' => 'customer_id',
    'quickRoute' => route('sales.customers.quick-store'),
])
@endsection
