<details @if(($showResponseForm ?? false) || $errors->has('contact_name') || $errors->has('note')) open @endif>
    <summary>Record a response</summary>
    <p class="sw-muted">Record the customer’s response if they confirmed by phone, email or in person.</p>
    <form method="post" action="{{ route('quotations.action', $quote) }}">
        @csrf
        <input type="hidden" name="lock_version" value="{{ $quote->lock_version }}">
        @if($returnToEnquiry ?? false)
            <input type="hidden" name="return_to_enquiry" value="1">
        @endif
        <div class="sw-field">
            <label for="response">Response</label>
            <select id="response" name="action">
                <option value="accepted" @selected(old('action') === 'accepted')>Accepted</option>
                <option value="declined" @selected(old('action') === 'declined')>Declined</option>
            </select>
        </div>
        <details class="sw-response-contact" @if($errors->has('contact_name') || old('contact_name')) open @endif>
            <summary>Different customer contact? <span class="sw-meta">Optional</span></summary>
            <div class="sw-field"><label for="contact-name">Contact name</label><input name="contact_name" id="contact-name" maxlength="150" value="{{ old('contact_name') }}" placeholder="Uses the quotation recipient when left blank"></div>
        </details>
        <div class="sw-field" style="margin-top:12px">
            <label for="response-note">How was this confirmed? *</label>
            <textarea name="note" id="response-note" required rows="3" maxlength="3000" placeholder="e.g. Confirmed by email">{{ old('note') }}</textarea>
        </div>
        <button class="sw-btn" style="margin-top:14px">Save quotation response</button>
    </form>
</details>
