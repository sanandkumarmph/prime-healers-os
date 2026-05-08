@extends('layouts.app')

@section('content')
<style>
    .customer-form-page { padding: 18px 22px 28px; display: grid; gap: 16px; }
    .customer-form-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .customer-form-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .customer-form-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .form-card {
        background:#fff;
        border:1px solid #dbe3ef;
        border-radius:14px;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .form-card-body { padding:18px; }
    .ops-btn-light {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        padding:8px 12px;
        border-radius:10px;
        font-size:13px;
        font-weight:600;
        text-decoration:none;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#334155;
    }
    .error-box {
        background:#fee2e2;
        border:1px solid #fecaca;
        color:#991b1b;
        border-radius:12px;
        padding:14px 16px;
    }
    .error-box ul { margin:0; padding-left:18px; }
    @media (max-width: 720px) {
        .customer-form-page { padding:14px; }
    }
</style>

<div class="container customer-form-page">
    <div class="customer-form-header">
        <div>
            <h1>Edit Customer</h1>
            <p>Update contact details, WhatsApp reachability, billing data, and delivery location without leaving the operations flow.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ route('customers.show', $customer) }}" class="ops-btn-light">View Profile</a>
            <a href="{{ route('customers.index') }}" class="ops-btn-light">Back to Customers</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="error-box">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="form-card">
        <div class="form-card-body">
            <form action="{{ route('customers.update', $customer) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('customers.partials.form', [
                    'customer' => $customer,
                    'submitLabel' => 'Update Customer',
                    'cancelUrl' => route('customers.show', $customer),
                ])
            </form>
        </div>
    </div>
</div>
@endsection
