<?php

namespace App\Http\Controllers;

use App\Models\ReferralSource;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ReferralSourceController extends Controller
{
    private const TYPES = [
        'doctor' => 'Doctor',
        'hospital' => 'Hospital',
        'business_partner' => 'Business Partner',
        'customer_referral' => 'Customer Referral',
        'employee' => 'Employee',
        'digital_marketing' => 'Digital Marketing',
        'walk_in' => 'Walk-in',
        'other' => 'Other',
    ];

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scoped(ReferralSource $referralSource): ReferralSource
    {
        abort_if((int) $referralSource->organization_id !== $this->orgId(), 403);

        return $referralSource;
    }

    private function rules(?ReferralSource $referralSource = null): array
    {
        return [
            'source_type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'name' => [
                'required',
                'string',
                'max:180',
                Rule::unique('referral_sources', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $this->orgId()))
                    ->ignore($referralSource?->id),
            ],
            'contact' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $type = trim((string) $request->get('source_type', ''));

        if (! Schema::hasTable('referral_sources')) {
            return view('referral-sources.index', [
                'referralSources' => new LengthAwarePaginator([], 0, 20, 1, [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]),
                'search' => $search,
                'type' => $type,
                'types' => self::TYPES,
                'migrationMissing' => true,
            ]);
        }

        $referralSources = ReferralSource::query()
            ->forOrganization($this->orgId())
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('contact', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            })
            ->when($type !== '', fn ($query) => $query->where('source_type', $type))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('referral-sources.index', [
            'referralSources' => $referralSources,
            'search' => $search,
            'type' => $type,
            'types' => self::TYPES,
            'migrationMissing' => false,
        ]);
    }

    public function create()
    {
        return view('referral-sources.create', [
            'referralSource' => new ReferralSource(['is_active' => true]),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $referralSource = ReferralSource::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return redirect()->route('referral-sources.index')->with('success', 'Referral source added.');
    }

    public function quickStore(Request $request)
    {
        $validated = $request->validate($this->rules());

        $referralSource = ReferralSource::create([
            ...$validated,
            'organization_id' => $this->orgId(),
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $referralSource->id,
            'source_type' => $referralSource->source_type,
            'name' => $referralSource->name,
            'contact' => $referralSource->contact,
            'city' => $referralSource->city,
        ], 201);
    }

    public function edit(ReferralSource $referralSource)
    {
        return view('referral-sources.edit', [
            'referralSource' => $this->scoped($referralSource),
            'types' => self::TYPES,
        ]);
    }

    public function update(Request $request, ReferralSource $referralSource)
    {
        $referralSource = $this->scoped($referralSource);
        $validated = $request->validate($this->rules($referralSource));

        $referralSource->update([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('referral-sources.index')->with('success', 'Referral source updated.');
    }
}
