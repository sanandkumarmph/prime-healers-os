@extends('layouts.app')

@section('content')
    @include('roles._form', ['role' => $role, 'permissionModules' => $permissionModules, 'permissionActions' => $permissionActions])
@endsection
