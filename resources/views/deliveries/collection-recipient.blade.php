<label>Who received the customer's payment?
<select name="collection_recipient" @required($required ?? false)>
    <option value="">Choose who received it</option>
    <option value="company" @selected(old('collection_recipient') === 'company')>Prime Healers / direct to company</option>
    <option value="vendor" @selected(old('collection_recipient') === 'vendor')>Vendor · {{ $collectionVendor['name'] }}</option>
</select>
</label>
@error('collection_recipient')<p class="sw-muted" role="alert">{{ $message }}</p>@enderror
