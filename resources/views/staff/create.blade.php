@extends('layouts.app')

@section('content')
<div class="container" style="padding:20px 24px 32px;">
    <form action="{{ route('staff.store') }}" method="POST">
        @csrf
        @include('staff._form', ['isEdit' => false])
    </form>
</div>
@endsection
