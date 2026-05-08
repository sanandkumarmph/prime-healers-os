@extends('layouts.app')

@section('content')
    @include('warehouses._form', ['warehouse' => $warehouse])
@endsection
