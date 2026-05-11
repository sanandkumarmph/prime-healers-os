@php
    $indianStates = [
        'Andhra Pradesh',
        'Arunachal Pradesh',
        'Assam',
        'Bihar',
        'Chhattisgarh',
        'Goa',
        'Gujarat',
        'Haryana',
        'Himachal Pradesh',
        'Jharkhand',
        'Karnataka',
        'Kerala',
        'Madhya Pradesh',
        'Maharashtra',
        'Manipur',
        'Meghalaya',
        'Mizoram',
        'Nagaland',
        'Odisha',
        'Punjab',
        'Rajasthan',
        'Sikkim',
        'Tamil Nadu',
        'Telangana',
        'Tripura',
        'Uttar Pradesh',
        'Uttarakhand',
        'West Bengal',
        'Andaman and Nicobar Islands',
        'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi',
        'Jammu and Kashmir',
        'Ladakh',
        'Lakshadweep',
        'Puducherry',
    ];
@endphp

@extends('layouts.app')

@section('content')
<div style="max-width:1180px; margin:0 auto;">
    <style>
        .org-settings-shell {
            display: grid;
            gap: 22px;
        }

        .org-settings-hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding: 30px;
            border-radius: 28px;
            border: 1px solid #dbe7f3;
            background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .org-settings-hero h1 {
            margin: 0 0 8px;
            font-size: 34px;
            line-height: 1.05;
            color: #0f172a;
        }

        .org-settings-hero p {
            margin: 0;
            color: #64748b;
            font-size: 15px;
            line-height: 1.7;
            max-width: 680px;
        }

        .org-settings-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .org-alert-success,
        .org-alert-error {
            padding: 14px 16px;
            border-radius: 18px;
        }

        .org-alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .org-alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .org-alert-error ul {
            margin: 0;
            padding-left: 18px;
        }

        .org-panel-grid {
            display: grid;
            gap: 22px;
        }

        .org-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .org-panel-header {
            padding: 22px 24px 0;
        }

        .org-panel-title {
            margin: 0;
            font-size: 20px;
            color: #0f172a;
        }

        .org-panel-subtitle {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .org-panel-body {
            padding: 24px;
        }

        .org-grid-2,
        .org-grid-3 {
            display: grid;
            gap: 16px;
        }

        .org-grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .org-grid-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .org-label {
            display: block;
            margin-bottom: 8px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
        }

        .org-input,
        .org-textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            outline: none;
        }

        .org-textarea {
            min-height: 140px;
            resize: vertical;
        }

        .org-input:focus,
        .org-textarea:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .org-file-card {
            padding: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .org-file-preview {
            margin-top: 14px;
            padding: 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            background: #f8fafc;
        }

        .org-file-preview img {
            max-width: 220px;
            max-height: 160px;
            display: block;
            object-fit: contain;
        }

        .org-actions {
            display: flex;
            justify-content: flex-end;
        }

        .org-save-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 20px;
            border: 1px solid #0f172a;
            border-radius: 12px;
            background: #0f172a;
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16);
        }

        @media (max-width: 900px) {
            .org-settings-hero,
            .org-grid-2,
            .org-grid-3 {
                grid-template-columns: 1fr;
                display: grid;
            }
        }

        @media (max-width: 640px) {
            .org-settings-hero,
            .org-panel-body {
                padding: 18px;
            }

            .org-panel-header {
                padding: 18px 18px 0;
            }

            .org-panel,
            .org-file-card {
                border-radius: 18px;
            }

            .org-actions {
                justify-content: stretch;
            }

            .org-save-button {
                width: 100%;
            }
        }
    </style>

    <div class="org-settings-shell">
        <div class="org-settings-hero">
            <div>
                <h1>Company Settings</h1>
                <p>Manage Prime Healers invoice, payment, and branding defaults from the same internal admin control center used for users, roles, cities, warehouses, and vendors.</p>
            </div>
            <div class="org-settings-badge">Super Admin</div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px;">
            <a href="{{ route('users.index') }}" style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #dbe7f3; background:#ffffff; text-decoration:none;">
                <span style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Users</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Manage Accounts</span>
            </a>
            <a href="{{ route('roles.index') }}" style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #dbe7f3; background:#ffffff; text-decoration:none;">
                <span style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Roles</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Permission Matrix</span>
            </a>
            <a href="{{ route('cities.index') }}" style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #dbe7f3; background:#ffffff; text-decoration:none;">
                <span style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Cities</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Reusable Master</span>
            </a>
            <a href="{{ route('warehouses.index') }}" style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #dbe7f3; background:#ffffff; text-decoration:none;">
                <span style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Warehouses</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Location Control</span>
            </a>
            <a href="{{ route('vendors.index') }}" style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #dbe7f3; background:#ffffff; text-decoration:none;">
                <span style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:700;">Vendors</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Third Parties</span>
            </a>
            <div style="display:flex; flex-direction:column; gap:4px; padding:16px; border-radius:18px; border:1px solid #cce7df; background:#ecfeff;">
                <span style="font-size:12px; color:#0f766e; text-transform:uppercase; font-weight:700;">Settings</span>
                <span style="font-size:15px; font-weight:700; color:#0f172a;">Current Page</span>
            </div>
        </div>

        @if(session('success'))
            <div class="org-alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="org-alert-error">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('organization.settings.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="org-panel-grid">
                <div class="org-panel">
                    <div class="org-panel-header" id="company">
                        <h2 class="org-panel-title">Company Details</h2>
                        <p class="org-panel-subtitle">These values appear in invoice headers and customer-facing communication.</p>
                    </div>

                    <div class="org-panel-body">
                        <div class="org-grid-2">
                            <div>
                                <label class="org-label">Company Name</label>
                                <input type="text" name="name" value="{{ old('name', $organization->name) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">GST Number</label>
                                <input type="text" name="gst_number" value="{{ old('gst_number', $organization->gst_number) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Phone</label>
                                <div style="display:flex; align-items:center; border:1px solid #d4dce8; border-radius:14px; overflow:visible; background:#fff;">
                                    @php($organizationPhoneParts = \App\Support\PhoneNumber::split(old('phone', $organization->phone)))
                                    @include('partials.country-code-picker', [
                                        'name' => 'phone_country_code',
                                        'pickerId' => 'phone_country_code',
                                        'value' => old('phone_country_code', $organizationPhoneParts['code']),
                                        'options' => \App\Support\PhoneNumber::countryCodeOptions(),
                                        'dividerColor' => '#d4dce8',
                                        'width' => '92px',
                                    ])
                                    <input type="text" name="phone" value="{{ $organizationPhoneParts['local'] }}" class="org-input" inputmode="numeric" maxlength="15" pattern="[0-9]{6,15}" data-phone-local style="border:none; box-shadow:none;">
                                </div>
                            </div>

                            <div>
                                <label class="org-label">Email</label>
                                <input type="email" name="email" value="{{ old('email', $organization->email) }}" class="org-input">
                            </div>
                        </div>

                        <div style="margin-top:16px;">
                            <label class="org-label">Address</label>
                            <textarea name="address" class="org-textarea" style="min-height:110px;">{{ old('address', $organization->address) }}</textarea>
                        </div>

                        <div class="org-grid-3" style="margin-top:16px;">
                            <div>
                                <label class="org-label">City</label>
                                <input type="text" name="city" value="{{ old('city', $organization->city) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">State</label>
                                <input type="text" name="state" list="org_indian_states" value="{{ old('state', $organization->state) }}" class="org-input">
                                <datalist id="org_indian_states">
                                    @foreach($indianStates as $stateOption)
                                        <option value="{{ $stateOption }}"></option>
                                    @endforeach
                                </datalist>
                            </div>

                            <div>
                                <label class="org-label">State Code</label>
                                <input type="text" name="state_code" value="{{ old('state_code', $organization->state_code) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Pincode</label>
                                <input type="text" name="pincode" value="{{ old('pincode', $organization->pincode) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Country</label>
                                <input type="text" name="country" value="{{ old('country', $organization->country) }}" class="org-input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="org-panel">
                    <div class="org-panel-header">
                        <h2 class="org-panel-title">Banking & UPI</h2>
                        <p class="org-panel-subtitle">These values can be printed on invoices for easier customer payments.</p>
                    </div>

                    <div class="org-panel-body">
                        <div class="org-grid-2">
                            <div>
                                <label class="org-label">Bank Account Name</label>
                                <input type="text" name="bank_account_name" value="{{ old('bank_account_name', $organization->bank_account_name) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Bank Account Number</label>
                                <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $organization->bank_account_number) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">IFSC</label>
                                <input type="text" name="bank_ifsc" value="{{ old('bank_ifsc', $organization->bank_ifsc) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Bank Name</label>
                                <input type="text" name="bank_name" value="{{ old('bank_name', $organization->bank_name) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">Branch</label>
                                <input type="text" name="bank_branch" value="{{ old('bank_branch', $organization->bank_branch) }}" class="org-input">
                            </div>

                            <div>
                                <label class="org-label">UPI ID</label>
                                <input type="text" name="upi_id" value="{{ old('upi_id', $organization->upi_id) }}" class="org-input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="org-panel">
                    <div class="org-panel-header">
                        <h2 class="org-panel-title">Invoice Assets & Defaults</h2>
                        <p class="org-panel-subtitle">Upload payment QR and signature once, and reuse them across printed invoices.</p>
                    </div>

                    <div class="org-panel-body">
                        <div class="org-grid-3">
                            <div class="org-file-card">
                                <label class="org-label">Organization Logo</label>
                                <input type="file" name="logo" accept="image/*" class="org-input">

                                @if($organization->logo)
                                    <div class="org-file-preview">
                                        <img src="{{ asset('storage/' . $organization->logo) }}" alt="Organization Logo">
                                    </div>
                                @endif
                            </div>

                            <div class="org-file-card">
                                <label class="org-label">Payment QR Code</label>
                                <input type="file" name="payment_qr_code" accept="image/*" class="org-input">

                                @if($organization->payment_qr_code)
                                    <div class="org-file-preview">
                                        <img src="{{ asset('storage/' . $organization->payment_qr_code) }}" alt="Payment QR Code">
                                    </div>
                                @endif
                            </div>

                            <div class="org-file-card">
                                <label class="org-label">Digital Signature</label>
                                <input type="file" name="digital_signature" accept="image/*" class="org-input">

                                @if($organization->digital_signature)
                                    <div class="org-file-preview">
                                        <img src="{{ asset('storage/' . $organization->digital_signature) }}" alt="Digital Signature">
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div style="margin-top:18px;">
                            <label class="org-label">Default Invoice Terms</label>
                            <textarea name="default_terms" class="org-textarea">{{ old('default_terms', $organization->default_terms) }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="org-panel" id="preferences">
                    <div class="org-panel-header">
                        <h2 class="org-panel-title">Preferences</h2>
                        <p class="org-panel-subtitle">This section is ready for the next implementation pass, so the Preferences link lands on something useful instead of a dead end.</p>
                    </div>

                    <div class="org-panel-body">
                        <div class="org-grid-2">
                            @foreach ([
                                ['title' => 'Company Preferences', 'copy' => 'Locale, numbering style, and organization-wide display defaults.'],
                                ['title' => 'Rental Settings', 'copy' => 'Default renewal timing, return reminders, and operational workflow preferences.'],
                                ['title' => 'Invoice Settings', 'copy' => 'Draft numbering behavior, due-date defaults, and print presentation preferences.'],
                                ['title' => 'Notification Settings', 'copy' => 'Reminder channels and team follow-up preferences for operations and finance.'],
                                ['title' => 'Branding Settings', 'copy' => 'Shared logo, footer, signature, and customer-facing presentation defaults.'],
                            ] as $preferenceCard)
                                <div class="org-file-card" style="display:grid; gap:10px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                        <div>
                                            <div class="org-label" style="margin-bottom:4px;">{{ $preferenceCard['title'] }}</div>
                                            <div style="color:#64748b; font-size:13px; line-height:1.6;">{{ $preferenceCard['copy'] }}</div>
                                        </div>
                                        <span class="rn-badge rn-badge-draft">Coming Soon</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="org-actions">
                    <button type="submit" class="org-save-button">Save Company Settings</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('[data-phone-local]').forEach(function (input) {
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D+/g, '').slice(0, 15);
    });
});
</script>
@endsection
