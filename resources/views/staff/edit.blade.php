@extends('layouts.app')

@section('content')
<div class="container" style="padding:20px 24px 32px;">
    <form action="{{ route('staff.update', $staff->id) }}" method="POST">
        @csrf
        @method('PUT')
        @include('staff._form', ['isEdit' => true])
    </form>
</div>
@endsection
