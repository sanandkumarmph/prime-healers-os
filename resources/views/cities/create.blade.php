@extends('layouts.app')

@section('content')
    @include('cities._form', ['city' => $city])
@endsection
