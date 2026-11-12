@extends('layouts.app')

@section('content')
    @include('customers.partials.form-page', ['customer' => $customer])
@endsection
