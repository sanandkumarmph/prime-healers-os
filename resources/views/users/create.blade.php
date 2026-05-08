@extends('layouts.app')

@section('content')
    @include('users._form', ['user' => $user, 'roles' => $roles, 'cities' => $cities])
@endsection
