@extends('layouts.app')

@section('content')
    @include('vendors._form', ['vendor' => $vendor, 'cities' => $cities])
@endsection
