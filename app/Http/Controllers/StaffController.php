<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function baseStaffQuery()
    {
        return Staff::query()
            ->forOrganization($this->orgId())
            ->withCount([
                'recentDeliveryAssignments as delivery_assignments_count',
                'recentPickupAssignments as pickup_assignments_count',
            ]);
    }

    private function filteredStaffQuery(Request $request)
    {
        return $this->baseStaffQuery()
            ->search($request->string('search')->toString())
            ->roleFilter($request->string('role')->toString())
            ->statusFilter($request->string('status')->toString());
    }

    private function staffFormData(?Staff $staff = null): array
    {
        $roleOptions = collect(Staff::ROLE_OPTIONS)
            ->mapWithKeys(fn (string $role) => [$role => Staff::roleLabel($role)])
            ->all();

        $assignmentRoleOptions = collect(Staff::assignmentRoleOptions())
            ->mapWithKeys(fn (string $role) => [$role => Staff::roleLabel($role)])
            ->all();

        $defaultRole = old('role', $staff?->role ? Staff::normalizedRole($staff->role) : 'office');
        $defaultAssignmentRole = old(
            'assignment_role',
            $staff?->effective_role ?? ($defaultRole ?: 'office')
        );
        $defaultAssignmentEnabled = (string) old(
            'is_assignment_enabled',
            $staff
                ? ((int) $staff->assignment_eligible || (Staff::hasAssignmentEnabledColumn() ? (int) $staff->is_assignment_enabled : 0))
                : 1
        );

        return compact(
            'roleOptions',
            'assignmentRoleOptions',
            'defaultRole',
            'defaultAssignmentRole',
            'defaultAssignmentEnabled'
        );
    }

    private function validatedStaffData(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|in:' . implode(',', Staff::ROLE_OPTIONS),
            'assignment_role' => 'nullable|in:' . implode(',', Staff::assignmentRoleOptions()),
            'is_assignment_enabled' => 'nullable|boolean',
            'phone' => PhoneNumber::validationRules(),
            'phone_country_code' => 'nullable|string|max:8',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
            'joining_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ]);

        $validated['role'] = Staff::normalizedRole($validated['role']);
        $validated['assignment_role'] = Staff::normalizedRole($validated['assignment_role'] ?? $validated['role']);
        $validated = PhoneNumber::normalizeFields($validated, ['phone']);

        $validated['is_assignment_enabled'] = $request->boolean('is_assignment_enabled');

        if ($validated['status'] !== 'active') {
            $validated['is_assignment_enabled'] = false;
        }

        if (!Staff::hasAssignmentRoleColumn()) {
            unset($validated['assignment_role']);
        }

        if (!Staff::hasAssignmentEnabledColumn()) {
            unset($validated['is_assignment_enabled']);
        }

        if (!Staff::hasCityColumn()) {
            unset($validated['city']);
        }

        if (!Staff::hasNotesColumn()) {
            unset($validated['notes']);
        }

        return $validated;
    }

    public function index(Request $request)
    {
        $staff = $this->filteredStaffQuery($request)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $roleOptions = collect(Staff::ROLE_OPTIONS)
            ->mapWithKeys(fn (string $role) => [$role => Staff::roleLabel($role)])
            ->all();

        return view('staff.index', compact('staff', 'roleOptions'));
    }

    public function create()
    {
        return view('staff.create', $this->staffFormData());
    }

    public function store(Request $request)
    {
        $data = $this->validatedStaffData($request);
        $data['organization_id'] = $this->orgId();

        Staff::create($data);

        return redirect()->route('staff.index')->with('success', 'Staff created successfully.');
    }

    public function edit($id)
    {
        $staff = Staff::where('organization_id', $this->orgId())->findOrFail($id);

        return view('staff.edit', array_merge(['staff' => $staff], $this->staffFormData($staff)));
    }

    public function show($id)
    {
        $staff = Staff::query()
            ->forOrganization($this->orgId())
            ->withCount([
                'recentDeliveryAssignments as delivery_assignments_count',
                'recentPickupAssignments as pickup_assignments_count',
            ])
            ->findOrFail($id);

        $recentDeliveries = $staff->recentDeliveryAssignments()
            ->with(['rental.product', 'rental.customer'])
            ->latest()
            ->limit(8)
            ->get();

        $recentPickups = $staff->recentPickupAssignments()
            ->with(['rental.product', 'rental.customer'])
            ->latest()
            ->limit(8)
            ->get();

        return view('staff.show', compact('staff', 'recentDeliveries', 'recentPickups'));
    }

    public function update(Request $request, $id)
    {
        $staff = Staff::where('organization_id', $this->orgId())->findOrFail($id);

        $staff->update($this->validatedStaffData($request));

        return redirect()->route('staff.index')->with('success', 'Staff updated successfully.');
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $staffMembers = $this->filteredStaffQuery($request)
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($staffMembers) {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'Name',
                'Phone',
                'Email',
                'Role',
                'Assignment Role',
                'Assignment Eligible',
                'Status',
                'City',
                'Delivery Assignments',
                'Pickup Assignments',
                'Joining Date',
            ]);

            foreach ($staffMembers as $staff) {
                fputcsv($output, [
                    $staff->name,
                    $staff->phone,
                    $staff->email,
                    $staff->role_display,
                    Staff::hasAssignmentRoleColumn() ? $staff->assignment_display : $staff->role_display,
                    $staff->assignment_eligible ? 'Yes' : 'No',
                    ucfirst((string) $staff->status),
                    Staff::hasCityColumn() ? ($staff->city ?? '') : '',
                    (string) ($staff->delivery_assignments_count ?? 0),
                    (string) ($staff->pickup_assignments_count ?? 0),
                    $staff->joining_date,
                ]);
            }

            fclose($output);
        }, 'staff-' . now()->format('Ymd-His') . '.csv');
    }

    public function destroy($id)
    {
        $staff = Staff::where('organization_id', $this->orgId())->findOrFail($id);

        $staff->delete();

        return redirect()->route('staff.index')->with('success', 'Staff deleted successfully.');
    }
}
