@csrf
<div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:18px;">
    <div style="display:grid; gap:6px;">
        <label style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">Source Type</label>
        <select name="source_type" style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px; background:#fff;">
            <option value="">Select type</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" @selected(old('source_type', $referralSource->source_type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('source_type')<span style="color:#b91c1c; font-size:12px;">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; gap:6px;">
        <label style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">Referral Person / Source Name</label>
        <input type="text" name="name" value="{{ old('name', $referralSource->name) }}" required style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px;">
        @error('name')<span style="color:#b91c1c; font-size:12px;">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; gap:6px;">
        <label style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">Contact Number</label>
        <input type="text" name="contact" value="{{ old('contact', $referralSource->contact) }}" style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px;">
        @error('contact')<span style="color:#b91c1c; font-size:12px;">{{ $message }}</span>@enderror
    </div>

    <div style="display:grid; gap:6px;">
        <label style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">City</label>
        <input type="text" name="city" value="{{ old('city', $referralSource->city) }}" style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px;">
        @error('city')<span style="color:#b91c1c; font-size:12px;">{{ $message }}</span>@enderror
    </div>

    <div style="grid-column:1 / -1; display:grid; gap:6px;">
        <label style="font-size:12px; font-weight:800; color:#475569; text-transform:uppercase;">Notes</label>
        <textarea name="notes" rows="3" style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px;">{{ old('notes', $referralSource->notes) }}</textarea>
        @error('notes')<span style="color:#b91c1c; font-size:12px;">{{ $message }}</span>@enderror
    </div>

    <label style="display:flex; gap:8px; align-items:center; color:#334155; font-weight:700;">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $referralSource->is_active ?? true))>
        Active referral source
    </label>

    <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px;">
        <a href="{{ route('referral-sources.index') }}" style="padding:11px 15px; border-radius:12px; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:800;">Cancel</a>
        <button type="submit" style="padding:11px 16px; border:0; border-radius:12px; background:#3150ff; color:#fff; font-weight:800;">Save Referral Source</button>
    </div>
</div>
