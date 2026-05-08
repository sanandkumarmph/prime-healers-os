@extends('layouts.app')

@section('content')
    @include('products._form', ['product' => $product])
@endsection
