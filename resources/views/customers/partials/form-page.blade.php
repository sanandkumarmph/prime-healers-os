@php
    $isCustomerEdit = $customer !== null;
    $customersUrl = route('customers.index');
    $profileUrl = $isCustomerEdit ? route('customers.show', $customer) : null;
    $formAction = $isCustomerEdit ? route('customers.update', $customer) : route('customers.store');
@endphp

<style>
    .customer-form-page {
        --profile-ink: #172944;
        --profile-muted: #52657f;
        --profile-line: #dce5f1;
        --profile-blue: #234fb8;
        width: min(100%, 1040px);
        min-width: 0;
        margin-inline: auto;
        display: grid;
        gap: 12px;
        color: var(--profile-ink);
    }
    .customer-form-page .customer-form-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px 16px;
        padding: 12px 16px;
        border: 1px solid var(--profile-line);
        border-radius: 12px;
        background: #fff;
    }
    .customer-form-title { min-width: 0; }
    .customer-form-title h1 {
        margin: 0;
        color: var(--profile-ink);
        font-size: clamp(21px, 3vw, 27px);
        line-height: 1.2;
        font-weight: 800;
    }
    .customer-form-title p {
        margin: 4px 0 0;
        color: var(--profile-muted);
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .customer-form-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; }
    .customer-form-nav a {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 36px;
        color: var(--profile-blue);
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
    }
    .customer-form-nav .customer-view-profile {
        padding: 0 10px;
        border: 1px solid var(--profile-line);
        border-radius: 8px;
        background: #f6f9fe;
    }
    .customer-form-nav a:hover { text-decoration: underline; }
    .customer-form-nav a:focus-visible { outline: 2px solid var(--profile-blue); outline-offset: 2px; }
    .customer-form-page .customer-form-card { min-width: 0; margin: 0; }
    .customer-form-errors {
        padding: 10px 14px;
        border: 1px solid #efc6c4;
        border-radius: 10px;
        background: #fff5f4;
        color: #8f211a;
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .customer-form-errors ul { margin: 4px 0 0; padding-inline-start: 18px; }
    @media (max-width: 640px) {
        .customer-form-page { gap: 8px; }
        .customer-form-page .customer-form-header { padding: 10px 12px; }
        .customer-form-nav a { min-height: 40px; }
    }
</style>

<div class="customer-form-page" data-customer-form-page="{{ $isCustomerEdit ? 'edit' : 'create' }}">
    <header class="customer-form-header">
        <div class="customer-form-title">
            <h1>{{ $isCustomerEdit ? 'Edit Customer' : 'Add Customer' }}</h1>
            @if($isCustomerEdit)
                <p>{{ $customer->displayName() }}</p>
            @endif
        </div>
        <nav class="customer-form-nav" aria-label="Customer navigation">
            <a href="{{ $customersUrl }}" aria-label="Back to Customers"><span aria-hidden="true">&larr;</span> Customers</a>
            @if($isCustomerEdit)
                <a href="{{ $profileUrl }}" class="customer-view-profile">View Profile</a>
            @endif
        </nav>
    </header>

    @if($errors->any())
        <div id="customer-form-errors" class="customer-form-errors" role="alert">
            <strong>Check the highlighted fields</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="customer-profile-form" class="customer-form-card" action="{{ $formAction }}" method="POST" enctype="multipart/form-data">
        @csrf
        @if($isCustomerEdit)
            @method('PUT')
        @endif
        @include('customers.partials.form', [
            'customer' => $customer,
            'submitLabel' => $isCustomerEdit ? 'Update Customer' : 'Save Customer',
            'cancelUrl' => $profileUrl ?? $customersUrl,
        ])
    </form>
</div>
