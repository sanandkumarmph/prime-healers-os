@extends('layouts.app')

@section('content')
<div style="max-width:1100px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px;">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">Product Master</div>
            <h1 style="margin:10px 0 4px; font-size:30px;">Brand Master</h1>
            <p style="margin:0; color:#64748b;">Standard product brands used across product search, imports, exports, and reports.</p>
        </div>
        <a href="{{ route('product-brands.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#4f46e5; color:#fff; text-decoration:none; font-weight:800;">+ Add Brand</a>
    </div>

    <form method="GET" action="{{ route('product-brands.index') }}" style="display:flex; gap:10px; margin-bottom:14px;">
        <input type="search" name="search" value="{{ $search }}" placeholder="Search brands" style="flex:1; padding:11px 13px; border:1px solid #cbd5e1; border-radius:12px;">
        <button type="submit" style="padding:10px 14px; border:0; border-radius:12px; background:#0f172a; color:#fff; font-weight:800;">Search</button>
        <a href="{{ route('product-brands.index') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#fff; color:#0f172a; text-decoration:none; font-weight:700;">Reset</a>
    </form>

    <div style="display:grid; gap:10px;">
        @forelse($productBrands as $brand)
            <div style="display:grid; grid-template-columns:minmax(0, 1fr) auto auto; gap:12px; align-items:center; padding:14px; border:1px solid #e2e8f0; border-radius:16px; background:#fff;">
                <div>
                    <strong style="display:block; font-size:16px; color:#0f172a;">{{ $brand->name }}</strong>
                    <span style="color:#64748b; font-size:12px;">{{ number_format($brand->products_count) }} linked product(s)</span>
                </div>
                <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:{{ $brand->is_active ? '#dcfce7' : '#f1f5f9' }}; color:{{ $brand->is_active ? '#166534' : '#475569' }}; font-size:11px; font-weight:800;">
                    {{ $brand->is_active ? 'Active' : 'Inactive' }}
                </span>
                <div style="display:flex; gap:8px;">
                    <a href="{{ route('product-brands.edit', $brand) }}" style="padding:8px 11px; border-radius:10px; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:700;">Edit</a>
                    @if($brand->is_active)
                        <form method="POST" action="{{ route('product-brands.destroy', $brand) }}" onsubmit="return confirm('Deactivate this brand?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" style="padding:8px 11px; border:0; border-radius:10px; background:#fff7ed; color:#9a3412; font-weight:800;">Deactivate</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div style="padding:22px; border:1px dashed #cbd5e1; border-radius:18px; background:#fff; color:#64748b;">No product brands found.</div>
        @endforelse
    </div>

    <div style="margin-top:16px;">{{ $productBrands->links() }}</div>
</div>
@endsection
