@php
    $partnerClient = $partnerClient ?? null;
    $isEdit = $partnerClient !== null;
    $value = fn (string $field, $default = '') => old($field, $partnerClient?->{$field} ?? $default);
    $profileUrl = route('business-partners.show', $businessPartner);
    $mapFields = ['location', 'latitude', 'longitude'];
    $hasMap = collect($mapFields)->contains(fn ($field) => filled($value($field)));
    $fieldErrorAttributes = fn (string $field): string => $errors->has($field)
        ? 'aria-invalid="true" aria-describedby="' . $field . '-error"'
        : '';
@endphp

<style>
    .actual-client-form {
        --client-ink: #172944;
        --client-muted: #52657f;
        --client-line: #dce5f1;
        --client-input-line: #becce0;
        --client-blue: #234fb8;
        --client-blue-soft: #eef4ff;
        width: min(100%, 1040px);
        min-width: 0;
        margin-inline: auto;
        display: grid;
        gap: 12px;
        color: var(--client-ink);
    }
    .actual-client-form :focus-visible { outline: 2px solid var(--client-blue); outline-offset: 2px; }
    .actual-client-form :is(.actual-client-header, .actual-client-card, .actual-client-actions) {
        min-width: 0;
        border: 1px solid var(--client-line);
        border-radius: 12px;
        background: #fff;
    }
    .actual-client-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 8px 16px;
        padding: 12px 16px;
    }
    .actual-client-header > div { min-width: 0; }
    .actual-client-header h1 {
        margin: 0;
        color: var(--client-ink);
        font-size: clamp(21px, 3vw, 27px);
        font-weight: 800;
        line-height: 1.2;
    }
    .actual-client-header p { margin: 4px 0 0; font-size: 13px; color: var(--client-muted); overflow-wrap: anywhere; }
    .actual-client-back {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 40px;
        font-size: 13px;
        font-weight: 700;
        color: var(--client-blue);
        text-decoration: none;
    }
    .actual-client-back:hover { text-decoration: underline; }
    .actual-client-errors {
        padding: 10px 14px;
        border: 1px solid #efc6c4;
        border-radius: 10px;
        background: #fff5f4;
        color: #8f211a;
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .actual-client-errors ul { margin: 4px 0 0; padding-inline-start: 18px; }
    .actual-client-section { display: grid; gap: 12px; padding: 16px; min-width: 0; }
    .actual-client-section + .actual-client-section { border-top: 1px solid var(--client-line); }
    .actual-client-section h2 { margin: 0; color: var(--client-ink); font-size: 16px; font-weight: 750; line-height: 1.25; }
    .actual-client-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px 12px; min-width: 0; }
    .actual-client-form .client-col-4 { grid-column: span 4; }
    .actual-client-form .client-col-6 { grid-column: span 6; }
    .actual-client-form .client-col-8 { grid-column: span 8; }
    .actual-client-form .client-col-12 { grid-column: 1 / -1; }
    .actual-client-field { display: grid; align-content: start; gap: 4px; min-width: 0; }
    .actual-client-field label { font-size: 13px; font-weight: 650; line-height: 1.3; }
    .actual-client-required { color: var(--client-blue); }
    .actual-client-field :is(input, select, textarea) {
        box-sizing: border-box;
        width: 100%;
        min-width: 0;
        min-height: 42px;
        padding: 8px 10px;
        border: 1px solid var(--client-input-line);
        border-radius: 8px;
        background: #fff;
        color: var(--client-ink);
        font-size: 14px;
        line-height: 1.4;
        scroll-margin-block: 90px 160px;
    }
    .actual-client-field textarea { min-height: 58px; resize: vertical; }
    .actual-client-field :is(input, select, textarea):focus {
        outline: none;
        border-color: var(--client-blue);
        box-shadow: 0 0 0 2px #d7e5ff;
    }
    .actual-client-field [aria-invalid="true"] { border-color: #b7352a; background: #fff6f5; }
    .actual-client-error { color: #9d281e; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    .actual-client-options { border: 1px solid var(--client-line); border-radius: 8px; }
    .actual-client-option + .actual-client-option { border-top: 1px solid var(--client-line); }
    .actual-client-option > summary {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px 8px;
        min-height: 42px;
        padding: 8px 12px;
        border-radius: 7px;
        list-style: none;
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
        scroll-margin-block: 90px 160px;
    }
    .actual-client-option > summary::-webkit-details-marker { display: none; }
    .actual-client-option > summary::after { content: '+'; margin-inline-start: auto; color: var(--client-blue); font-size: 17px; line-height: 1; }
    .actual-client-option[open] > summary::after { content: '−'; }
    .actual-client-option[open] > summary,
    .actual-client-option > summary:hover { background: var(--client-blue-soft); color: var(--client-blue); }
    .actual-client-option-status { color: var(--client-muted); font-size: 12px; font-weight: 500; }
    .actual-client-option-body { padding: 12px; }
    .actual-client-actions {
        position: sticky;
        z-index: 4;
        bottom: 12px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 9px 12px;
        box-shadow: 0 5px 16px rgba(15, 23, 42, .06);
    }
    .actual-client-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: 8px 14px;
        border: 1px solid var(--client-input-line);
        border-radius: 8px;
        background: #fff;
        color: var(--client-ink);
        font-size: 14px;
        font-weight: 650;
        text-align: center;
        text-decoration: none;
        cursor: pointer;
    }
    .actual-client-button:hover { background: var(--client-blue-soft); }
    .actual-client-button.is-primary { border-color: var(--client-blue); background: var(--client-blue); color: #fff; }
    .actual-client-button.is-primary:hover { border-color: #193e99; background: #193e99; }
    @media (max-width: 980px) {
        .actual-client-form { padding-bottom: 76px; }
        .actual-client-actions { position: fixed; inset-inline: 12px; bottom: 12px; z-index: 904; }
    }
    @media (max-width: 767px) {
        .actual-client-form { padding-bottom: 0; }
        .actual-client-actions { bottom: calc(82px + env(safe-area-inset-bottom, 0px)); }
        body:has([data-actual-client-form]) .phos-release-badge { bottom: calc(150px + env(safe-area-inset-bottom, 0px)); }
    }
    @media (max-width: 640px) {
        .actual-client-form { gap: 8px; }
        .actual-client-header, .actual-client-section { padding: 12px; }
        .actual-client-field :is(input, select, textarea) { font-size: 16px; }
        .actual-client-form .actual-client-contact > * { grid-column: 1 / -1; }
        .actual-client-form .actual-client-address > .client-col-4 { grid-column: span 6; }
        .actual-client-actions .is-primary { flex: 1; }
    }
</style>

<div class="actual-client-form" data-actual-client-form="{{ $isEdit ? 'edit' : 'create' }}">
    <header class="actual-client-header">
        <div>
            <h1>{{ $isEdit ? 'Edit Actual Client' : 'Add Actual Client' }}</h1>
            <p>Business partner <strong>{{ $businessPartner->displayName() }}</strong></p>
        </div>
        <a href="{{ $profileUrl }}#actual-clients" class="actual-client-back"><span aria-hidden="true">&larr;</span> Actual Clients</a>
    </header>

    @if($errors->any())
        <div class="actual-client-errors" role="alert">
            <strong>Check the highlighted fields</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="actual-client-card">
        <section class="actual-client-section" aria-labelledby="actual-client-contact-heading">
            <h2 id="actual-client-contact-heading">Contact</h2>
            <div class="actual-client-grid actual-client-contact">
                <div class="actual-client-field client-col-8">
                    <label for="client_name">Actual client name <span class="actual-client-required" aria-hidden="true">*</span></label>
                    <input type="text" name="client_name" id="client_name" value="{{ $value('client_name') }}" autocomplete="name" required {!! $fieldErrorAttributes('client_name') !!}>
                    @error('client_name')<div id="client_name-error" class="actual-client-error">{{ $message }}</div>@enderror
                </div>
                <div class="actual-client-field client-col-4">
                    <label for="status">Status</label>
                    <select name="status" id="status" {!! $fieldErrorAttributes('status') !!}>
                        <option value="active" @selected($value('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected($value('status', 'active') === 'inactive')>Inactive</option>
                    </select>
                    @error('status')<div id="status-error" class="actual-client-error">{{ $message }}</div>@enderror
                </div>
                @foreach(['phone' => 'Phone', 'alternate_phone' => 'Alternate phone'] as $field => $label)
                    <div class="actual-client-field client-col-6">
                        <label for="{{ $field }}">{{ $label }}</label>
                        <input type="text" inputmode="tel" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" @if($field === 'phone') autocomplete="tel" @endif {!! $fieldErrorAttributes($field) !!}>
                        @error($field)<div id="{{ $field }}-error" class="actual-client-error">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section class="actual-client-section" aria-labelledby="actual-client-address-heading">
            <h2 id="actual-client-address-heading">Delivery address</h2>
            <div class="actual-client-grid actual-client-address">
                <div class="actual-client-field client-col-12">
                    <label for="address">Street address</label>
                    <textarea name="address" id="address" rows="2" autocomplete="street-address" placeholder="Building, street, landmark" {!! $fieldErrorAttributes('address') !!}>{{ $value('address') }}</textarea>
                    @error('address')<div id="address-error" class="actual-client-error">{{ $message }}</div>@enderror
                </div>
                @foreach(['city' => ['City', 'address-level2'], 'state' => ['State', 'address-level1'], 'pincode' => ['Pincode', 'postal-code']] as $field => [$label, $autocomplete])
                    <div class="actual-client-field client-col-4">
                        <label for="{{ $field }}">{{ $label }}</label>
                        <input type="text" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" autocomplete="{{ $autocomplete }}" {!! $fieldErrorAttributes($field) !!}>
                        @error($field)<div id="{{ $field }}-error" class="actual-client-error">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="actual-client-card actual-client-section" aria-labelledby="actual-client-additional-heading">
        <h2 id="actual-client-additional-heading">Additional details</h2>
        <div class="actual-client-options">
            <details class="actual-client-option" data-actual-client-panel="location" @if($errors->hasAny($mapFields)) open @endif>
                <summary>
                    Map &amp; coordinates
                    @if($hasMap)<span class="actual-client-option-status">Location entered</span>@endif
                </summary>
                <div class="actual-client-option-body actual-client-grid">
                    <div class="actual-client-field client-col-12">
                        <label for="location">Map or location link</label>
                        <input type="text" name="location" id="location" value="{{ $value('location') }}" placeholder="Google Maps link or location note" {!! $fieldErrorAttributes('location') !!}>
                        @error('location')<div id="location-error" class="actual-client-error">{{ $message }}</div>@enderror
                    </div>
                    @foreach(['latitude' => 'Latitude', 'longitude' => 'Longitude'] as $field => $label)
                        <div class="actual-client-field client-col-6">
                            <label for="{{ $field }}">{{ $label }}</label>
                            <input type="number" step="any" name="{{ $field }}" id="{{ $field }}" value="{{ $value($field) }}" {!! $fieldErrorAttributes($field) !!}>
                            @error($field)<div id="{{ $field }}-error" class="actual-client-error">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </details>
            <details class="actual-client-option" data-actual-client-panel="notes" @if($errors->has('delivery_notes')) open @endif>
                <summary>
                    Delivery &amp; service notes
                    @if(filled($value('delivery_notes')))<span class="actual-client-option-status">Notes entered</span>@endif
                </summary>
                <div class="actual-client-option-body">
                    <div class="actual-client-field">
                        <label for="delivery_notes">Notes for the delivery team</label>
                        <textarea name="delivery_notes" id="delivery_notes" rows="2" {!! $fieldErrorAttributes('delivery_notes') !!}>{{ $value('delivery_notes') }}</textarea>
                        @error('delivery_notes')<div id="delivery_notes-error" class="actual-client-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </details>
        </div>
    </section>

    <div class="actual-client-actions" data-actual-client-form-actions>
        <a href="{{ $profileUrl }}" class="actual-client-button">Cancel</a>
        <button type="submit" class="actual-client-button is-primary">{{ $isEdit ? 'Update Actual Client' : 'Save Actual Client' }}</button>
    </div>
</div>

<script>
    document.getElementById('actual-client-form').addEventListener('invalid', event => {
        const panel = event.target.closest('[data-actual-client-panel]');
        if (panel) panel.open = true;
    }, true);
</script>
