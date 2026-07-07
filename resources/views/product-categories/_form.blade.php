@php
    $isEdit = $productCategory->exists;
@endphp

<div style="max-width:720px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Product Master</div>
            <h1 style="margin:10px 0 4px; font-size:30px;">{{ $isEdit ? 'Edit Category' : 'Add Category' }}</h1>
            <p style="margin:0; color:#64748b;">Keep product category labels clean and reusable.</p>
        </div>
        <a href="{{ route('product-categories.index') }}" style="display:inline-flex; padding:10px 14px; border:1px solid #cbd5e1; border-radius:12px; color:#0f172a; text-decoration:none; font-weight:700;">Back</a>
    </div>

    <form method="POST" action="{{ $isEdit ? route('product-categories.update', $productCategory) : route('product-categories.store') }}" style="display:grid; gap:14px; padding:18px; border:1px solid #e2e8f0; border-radius:18px; background:#fff;">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div>
            <label style="display:block; margin-bottom:7px; color:#475569; font-size:12px; font-weight:800;">Category Name</label>
            <input type="text" name="name" value="{{ old('name', $productCategory->name) }}" required style="width:100%; padding:11px 13px; border:1px solid #cbd5e1; border-radius:12px;">
            @error('name')
                <div style="margin-top:6px; color:#b91c1c; font-size:12px;">{{ $message }}</div>
            @enderror
        </div>

        <label style="display:flex; align-items:center; gap:10px; padding:11px 13px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $productCategory->is_active ?? true))>
            <span style="font-weight:700; color:#0f172a;">Active category</span>
        </label>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <a href="{{ route('product-categories.index') }}" style="padding:10px 14px; border:1px solid #cbd5e1; border-radius:12px; color:#0f172a; text-decoration:none; font-weight:700;">Cancel</a>
            <button type="submit" style="padding:10px 16px; border:0; border-radius:12px; background:#4f46e5; color:#fff; font-weight:800;">{{ $isEdit ? 'Update Category' : 'Save Category' }}</button>
        </div>
    </form>
</div>
