<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Availability\RentalAvailability;
use Illuminate\Http\Request;

class RentalAvailabilityController extends Controller
{
    public function index(Request $request, RentalAvailability $availability)
    {
        $availability->authorize($request->user());

        return view('availability.index', [
            'products' => Product::where('organization_id', $request->user()->organization_id)->orderBy('name')->get()->filter(fn ($p) => $p->canRent()),
            'warehouses' => $availability->warehouses($request->user())->orderBy('name')->get(),
        ]);
    }

    public function check(Request $request, RentalAvailability $availability)
    {
        $availability->authorize($request->user());
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'warehouse_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'between:1,10000'],
        ]);

        return response()->json($availability->check($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }
}
