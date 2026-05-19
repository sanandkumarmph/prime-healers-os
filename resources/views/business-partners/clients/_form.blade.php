@php
    $partnerClient = $partnerClient ?? null;
    $isEdit = (bool) $partnerClient;
@endphp

<style>
    .partner-client-shell { display:grid; gap:16px; max-width:1040px; margin:0 auto; padding:18px 22px 30px; }
    .partner-client-card { background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
    .partner-client-grid { display:grid; grid-template-columns:repeat(12, minmax(0,1fr)); gap:14px; }
    .partner-client-col-4 { grid-column:span 4; }
    .partner-client-col-6 { grid-column:span 6; }
    .partner-client-col-12 { grid-column:span 12; }
    .partner-client-field { display:grid; gap:6px; }
    .partner-client-field label { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#475569; }
    .partner-client-field input, .partner-client-field textarea, .partner-client-field select { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:14px; color:#0f172a; background:#fff; }
    .partner-client-field textarea { min-height:90px; resize:vertical; }
    .partner-client-field.is-error input, .partner-client-field.is-error textarea, .partner-client-field.is-error select { border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.08); background:#fff7f7; }
    .partner-client-error { color:#b91c1c; font-size:12px; }
    .partner-client-actions { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .partner-client-btn, .partner-client-btn-light { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 14px; border-radius:12px; border:1px solid transparent; text-decoration:none; font-size:13px; font-weight:700; cursor:pointer; }
    .partner-client-btn { background:#0f172a; color:#fff; }
    .partner-client-btn-light { background:#fff; color:#334155; border-color:#cbd5e1; }
    @media (max-width: 900px) {
        .partner-client-col-4, .partner-client-col-6 { grid-column:span 12; }
    }
</style>

<div class="partner-client-shell">
    <div class="partner-client-card">
        <h1 style="margin:0 0 6px;color:#0f172a;">{{ $isEdit ? 'Edit Actual Client' : 'Add Actual Client' }}</h1>
        <p style="margin:0 0 14px;color:#64748b;font-size:13px;">These are the real delivery, pickup, and service locations that sit under <strong>{{ $businessPartner->displayName() }}</strong>.</p>

        <div class="partner-client-grid">
            <div class="partner-client-field partner-client-col-6{{ $errors->has('client_name') ? ' is-error' : '' }}">
                <label for="client_name">Actual Client Name</label>
                <input type="text" name="client_name" id="client_name" value="{{ old('client_name', $partnerClient->client_name ?? '') }}" required>
                @error('client_name')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-6{{ $errors->has('status') ? ' is-error' : '' }}">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <option value="active" {{ old('status', $partnerClient->status ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $partnerClient->status ?? 'active') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-client-field partner-client-col-4{{ $errors->has('phone') ? ' is-error' : '' }}">
                <label for="phone">Phone</label>
                <input type="text" name="phone" id="phone" value="{{ old('phone', $partnerClient->phone ?? '') }}">
                @error('phone')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-4{{ $errors->has('alternate_phone') ? ' is-error' : '' }}">
                <label for="alternate_phone">Alternate Phone</label>
                <input type="text" name="alternate_phone" id="alternate_phone" value="{{ old('alternate_phone', $partnerClient->alternate_phone ?? '') }}">
                @error('alternate_phone')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-4{{ $errors->has('location') ? ' is-error' : '' }}">
                <label for="location">Map / Location</label>
                <input type="text" name="location" id="location" value="{{ old('location', $partnerClient->location ?? '') }}">
                @error('location')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-client-field partner-client-col-12{{ $errors->has('address') ? ' is-error' : '' }}">
                <label for="address">Address</label>
                <textarea name="address" id="address">{{ old('address', $partnerClient->address ?? '') }}</textarea>
                @error('address')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-client-field partner-client-col-4{{ $errors->has('city') ? ' is-error' : '' }}">
                <label for="city">City</label>
                <input type="text" name="city" id="city" value="{{ old('city', $partnerClient->city ?? '') }}">
                @error('city')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-4{{ $errors->has('state') ? ' is-error' : '' }}">
                <label for="state">State</label>
                <input type="text" name="state" id="state" value="{{ old('state', $partnerClient->state ?? '') }}">
                @error('state')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-4{{ $errors->has('pincode') ? ' is-error' : '' }}">
                <label for="pincode">Pincode</label>
                <input type="text" name="pincode" id="pincode" value="{{ old('pincode', $partnerClient->pincode ?? '') }}">
                @error('pincode')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-client-field partner-client-col-6{{ $errors->has('latitude') ? ' is-error' : '' }}">
                <label for="latitude">Latitude</label>
                <input type="number" step="0.000001" name="latitude" id="latitude" value="{{ old('latitude', $partnerClient->latitude ?? '') }}">
                @error('latitude')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
            <div class="partner-client-field partner-client-col-6{{ $errors->has('longitude') ? ' is-error' : '' }}">
                <label for="longitude">Longitude</label>
                <input type="number" step="0.000001" name="longitude" id="longitude" value="{{ old('longitude', $partnerClient->longitude ?? '') }}">
                @error('longitude')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>

            <div class="partner-client-field partner-client-col-12{{ $errors->has('delivery_notes') ? ' is-error' : '' }}">
                <label for="delivery_notes">Delivery / Service Notes</label>
                <textarea name="delivery_notes" id="delivery_notes">{{ old('delivery_notes', $partnerClient->delivery_notes ?? '') }}</textarea>
                @error('delivery_notes')<div class="partner-client-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    <div class="partner-client-actions">
        <a href="{{ route('business-partners.show', $businessPartner) }}" class="partner-client-btn-light">Cancel</a>
        <button type="submit" class="partner-client-btn">{{ $isEdit ? 'Update Actual Client' : 'Save Actual Client' }}</button>
    </div>
</div>
