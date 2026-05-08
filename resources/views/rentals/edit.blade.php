@extends('layouts.app')

@section('content')
<div class="container" style="padding:20px 24px 32px;">
    <form action="{{ route('rentals.update', $rental) }}" method="POST">
        @csrf
        @method('PUT')
        @include('rentals._form')
    </form>
</div>

@include('customers.partials.quick-create-modal', [
    'quickModalId' => 'rentalQuickCustomerModal',
    'quickFormId' => 'rentalQuickCustomerForm',
    'quickSelectTarget' => 'customer_id',
    'quickRoute' => route('rentals.customers.quick-store'),
])
@endsection
