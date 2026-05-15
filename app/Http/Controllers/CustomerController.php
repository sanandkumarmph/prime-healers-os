<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\CustomerProfileSupport;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    private ?bool $salesTableExists = null;
    private ?bool $invoicesTableExists = null;

    private function orgId()
    {
        return auth()->user()->organization_id;
    }

    private function salesTableExists(): bool
    {
        return $this->salesTableExists ??= Schema::hasTable('sales');
    }

    private function invoicesTableExists(): bool
    {
        return $this->invoicesTableExists ??= Schema::hasTable('invoices');
    }

    private function customerBaseQuery()
    {
        $query = Customer::query()
            ->where('organization_id', $this->orgId())
            ->withCount(['rentals', 'sales', 'invoices'])
            ->withCount([
                'rentals as active_rentals_count' => function ($rentalQuery) {
                    $rentalQuery->where('status', 'active');
                },
                'invoices as unpaid_invoices_count' => function ($invoiceQuery) {
                    $invoiceQuery->where('payment_status', '!=', 'paid');
                },
            ]);

        return $query;
    }

    private function validatedDateFilters(Request $request): array
    {
        $validated = $request->validate([
            'created_date' => ['nullable', 'date'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $createdDate = trim((string) ($validated['created_date'] ?? ''));
        $fromDate = trim((string) ($validated['from_date'] ?? ''));
        $toDate = trim((string) ($validated['to_date'] ?? ''));

        if ($createdDate !== '') {
            $fromDate = $fromDate !== '' ? $fromDate : $createdDate;
            $toDate = $toDate !== '' ? $toDate : $createdDate;
        }

        $normalizedFromDate = $fromDate !== '' ? Carbon::parse($fromDate)->toDateString() : '';
        $normalizedToDate = $toDate !== '' ? Carbon::parse($toDate)->toDateString() : '';

        if (
            $normalizedFromDate !== ''
            && $normalizedToDate !== ''
            && Carbon::parse($normalizedFromDate)->greaterThan(Carbon::parse($normalizedToDate))
        ) {
            throw ValidationException::withMessages([
                'to_date' => ['To Date must be on or after From Date.'],
            ]);
        }

        return [
            'createdDate' => $createdDate !== '' ? Carbon::parse($createdDate)->toDateString() : '',
            'fromDate' => $normalizedFromDate,
            'toDate' => $normalizedToDate,
        ];
    }

    private function applyCustomerFilters($query, Request $request, ?array $dateFilters = null)
    {
        $search = trim((string) $request->get('search', ''));
        $city = trim((string) $request->get('city', ''));
        $state = trim((string) $request->get('state', ''));
        $status = trim((string) $request->get('status', ''));
        $dateFilters ??= $this->validatedDateFilters($request);
        $fromDate = $dateFilters['fromDate'] ?? '';
        $toDate = $dateFilters['toDate'] ?? '';

        if ($search !== '') {
            $query->where(function ($customerQuery) use ($search) {
                $customerQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%')
                    ->orWhere('state', 'like', '%' . $search . '%')
                    ->orWhere('company_name', 'like', '%' . $search . '%');

                if (Customer::hasContactNameColumn()) {
                    $customerQuery->orWhere('contact_name', 'like', '%' . $search . '%');
                }

                if (Customer::hasWhatsappNumberColumn()) {
                    $customerQuery->orWhere('whatsapp_number', 'like', '%' . $search . '%');
                }

                if (Customer::hasCustomerCodeColumn()) {
                    $customerQuery->orWhere('customer_code', 'like', '%' . $search . '%');
                }
            });
        }

        if ($city !== '') {
            $query->where('city', 'like', '%' . $city . '%');
        }

        if ($state !== '') {
            $query->where('state', 'like', '%' . $state . '%');
        }

        if ($status !== '' && Customer::hasStatusColumn()) {
            $query->where('status', $status);
        }

        if ($fromDate !== '' && $toDate !== '') {
            $query->whereBetween('created_at', [
                Carbon::parse($fromDate)->startOfDay(),
                Carbon::parse($toDate)->endOfDay(),
            ]);
        } elseif ($fromDate !== '') {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->startOfDay());
        } elseif ($toDate !== '') {
            $query->where('created_at', '<=', Carbon::parse($toDate)->endOfDay());
        }

        return $query;
    }

    private function filterOptions(): array
    {
        $cities = Customer::query()
            ->where('organization_id', $this->orgId())
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $states = Customer::query()
            ->where('organization_id', $this->orgId())
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->orderBy('state')
            ->pluck('state');

        $indianStates = Customer::indianStates();
        $customerTypeOptions = ['Individual', 'Business'];
        $salutationOptions = ['Mr.', 'Mrs.', 'Ms.', 'Miss', 'Dr.', 'Prof.', 'Mx.'];
        $idProofTypeOptions = ['Aadhaar', 'PAN', 'Driving License', 'Passport', 'Voter ID', 'Other'];

        return compact('cities', 'states', 'indianStates', 'customerTypeOptions', 'salutationOptions', 'idProofTypeOptions');
    }

    private function applyCustomerSorting($query, string $sortBy)
    {
        return match ($sortBy) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderByDesc('created_at'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('created_at'),
            default => $query->latest(),
        };
    }

    private function streamCustomersCsv($customers, string $filename)
    {
        return response()->streamDownload(function () use ($customers) {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'Customer Name',
                'Phone',
                'WhatsApp',
                'Email',
                'City',
                'State',
                'Address',
                'Rentals Count',
                'Active Rentals Count',
                'Sales Count',
                'Invoice Count',
                'Created Date',
            ]);

            foreach ($customers as $customer) {
                fputcsv($output, [
                    $customer->name,
                    $customer->phone,
                    $customer->preferredWhatsAppNumber(),
                    $customer->email,
                    $customer->city,
                    $customer->state,
                    $customer->address,
                    $customer->rentals_count ?? 0,
                    $customer->active_rentals_count ?? 0,
                    $customer->sales_count ?? 0,
                    $customer->invoices_count ?? 0,
                    optional($customer->created_at)->format('Y-m-d'),
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function customerDeletionDependencies(Customer $customer): array
    {
        $dependencyLabels = [];

        if ($customer->rentals()->exists()) {
            $dependencyLabels[] = 'rentals';
        }

        if ($customer->sales()->exists()) {
            $dependencyLabels[] = 'sales';
        }

        if ($customer->invoices()->exists()) {
            $dependencyLabels[] = 'invoices';
        }

        if ($this->customerHasLinkedPayments($customer)) {
            $dependencyLabels[] = 'linked payments';
        }

        return $dependencyLabels;
    }

    private function customerHasLinkedPayments(Customer $customer): bool
    {
        if (!Schema::hasTable('payments')) {
            return false;
        }

        return Payment::query()
            ->when(Schema::hasColumn('payments', 'organization_id'), fn ($query) => $query->where('organization_id', $this->orgId()))
            ->where('customer_id', $customer->id)
            ->where(function ($query) {
                if (Schema::hasColumn('payments', 'rental_id')) {
                    $query->orWhereNotNull('rental_id');
                }

                if (Schema::hasColumn('payments', 'invoice_id')) {
                    $query->orWhereNotNull('invoice_id');
                }
            })
            ->exists();
    }

    private function deleteCustomerOrphanPayments(Customer $customer): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        Payment::query()
            ->when(Schema::hasColumn('payments', 'organization_id'), fn ($query) => $query->where('organization_id', $this->orgId()))
            ->where('customer_id', $customer->id)
            ->when(Schema::hasColumn('payments', 'rental_id'), fn ($query) => $query->whereNull('rental_id'))
            ->when(Schema::hasColumn('payments', 'invoice_id'), fn ($query) => $query->whereNull('invoice_id'))
            ->delete();
    }

    private function deleteCustomerRecord(Customer $customer): void
    {
        $this->deleteCustomerOrphanPayments($customer);

        $this->deleteCustomerProofFile($customer);

        $customer->delete();
    }

    private function customerProofDisk(Customer $customer): ?string
    {
        $path = (string) ($customer->id_proof_file_path ?? '');

        if ($path === '' || !Customer::hasIdProofFilePathColumn()) {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return 'local';
        }

        return null;
    }

    private function deleteCustomerProofFile(Customer $customer): void
    {
        if (!Customer::hasIdProofFilePathColumn() || empty($customer->id_proof_file_path)) {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($customer->id_proof_file_path)) {
                Storage::disk($disk)->delete($customer->id_proof_file_path);
            }
        }
    }

    private function selectedCustomerIds(Request $request): array
    {
        $validated = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
        ]);

        return collect($validated['customer_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        $dateFilters = $this->validatedDateFilters($request);
        $search = trim((string) $request->get('search', ''));
        $city = trim((string) $request->get('city', ''));
        $state = trim((string) $request->get('state', ''));
        $status = trim((string) $request->get('status', ''));
        $createdDate = $dateFilters['createdDate'];
        $fromDate = $dateFilters['fromDate'];
        $toDate = $dateFilters['toDate'];
        $sortBy = trim((string) $request->get('sort_by', 'latest'));

        $customers = $this->applyCustomerSorting(
            $this->applyCustomerFilters($this->customerBaseQuery(), $request, $dateFilters),
            $sortBy
        )
            ->paginate(12)
            ->withQueryString();

        $summaryBaseQuery = $this->applyCustomerFilters($this->customerBaseQuery(), $request, $dateFilters);
        $summaryCustomers = (clone $summaryBaseQuery)
            ->get([
                'customers.id',
                'rentals_count',
                'active_rentals_count',
                'sales_count',
                'invoices_count',
            ]);

        $totalCustomers = $summaryCustomers->count();
        $totalRentals = (int) $summaryCustomers->sum('rentals_count');
        $activeRentals = (int) $summaryCustomers->sum('active_rentals_count');
        $totalSales = (int) $summaryCustomers->sum('sales_count');
        $totalInvoices = (int) $summaryCustomers->sum('invoices_count');

        return view('customers.index', array_merge($this->filterOptions(), compact(
            'customers',
            'search',
            'city',
            'state',
            'status',
            'createdDate',
            'fromDate',
            'toDate',
            'sortBy',
            'totalCustomers',
            'totalRentals',
            'activeRentals',
            'totalSales',
            'totalInvoices'
        )));
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('export', Customer::class);

        $dateFilters = $this->validatedDateFilters($request);
        $sortBy = trim((string) $request->get('sort_by', 'latest'));

        $customers = $this->applyCustomerSorting(
            $this->applyCustomerFilters($this->customerBaseQuery(), $request, $dateFilters),
            $sortBy
        )
            ->get();

        return $this->streamCustomersCsv($customers, 'customers-' . now()->format('Ymd-His') . '.csv');
    }

    public function bulkExportCsv(Request $request)
    {
        $this->authorize('export', Customer::class);

        $customerIds = $this->selectedCustomerIds($request);

        $customers = $this->customerBaseQuery()
            ->whereIn('id', $customerIds)
            ->orderBy('name')
            ->get();

        return $this->streamCustomersCsv($customers, 'selected-customers-' . now()->format('Ymd-His') . '.csv');
    }

    public function create()
    {
        $this->authorize('create', Customer::class);

        return view('customers.create', $this->filterOptions());
    }

    public function store(Request $request)
    {
        $this->authorize('create', Customer::class);

        $validated = $this->validateCustomer($request);
        $customer = Customer::create($this->customerPayload($validated));
        $this->syncCustomerUploads($request, $customer);

        return redirect()->route('customers.index')
            ->with('success', 'Customer added successfully.');
    }

    public function show($id)
    {
        $customer = Customer::query()
            ->where('organization_id', $this->orgId())
            ->withCount(['rentals', 'sales', 'invoices'])
            ->withCount([
                'rentals as active_rentals_count' => function ($rentalQuery) {
                    $rentalQuery->where('status', 'active');
                },
                'rentals as returned_rentals_count' => function ($rentalQuery) {
                    $rentalQuery->where('status', 'returned');
                },
                'rentals as overdue_rentals_count' => function ($rentalQuery) {
                    $rentalQuery->overdue();
                },
                'rentals as ending_soon_rentals_count' => function ($rentalQuery) {
                    $rentalQuery->endingSoon();
                },
                'invoices as unpaid_invoices_count' => function ($invoiceQuery) {
                    $invoiceQuery->where('payment_status', '!=', 'paid');
                },
            ])
            ->with([
                'rentals' => function ($rentalQuery) {
                    $rentalQuery->with(['product', 'deliveryRecord', 'pickupRecord'])
                        ->latest()
                        ->limit(8);
                },
                'sales' => function ($saleQuery) {
                    $saleQuery->with('product')
                        ->latest()
                        ->limit(8);
                },
                'invoices' => function ($invoiceQuery) {
                    $invoiceQuery->latest()
                        ->limit(8);
                },
                'payments' => function ($paymentQuery) {
                    $paymentQuery->with('rental.product')
                        ->latest()
                        ->limit(8);
                },
            ])
            ->findOrFail($id);

        $this->authorize('view', $customer);

        return view('customers.show', compact('customer'));
    }

    public function downloadIdProof($id)
    {
        $customer = Customer::where('organization_id', $this->orgId())->findOrFail($id);
        $this->authorize('downloadProof', $customer);
        abort_unless(auth()->user()?->hasPermission('customers.proof.download') ?? false, 403);

        abort_unless(Customer::hasIdProofFilePathColumn() && filled($customer->id_proof_file_path), 404);

        $disk = $this->customerProofDisk($customer);
        abort_unless($disk !== null, 404);

        $filename = $customer->id_proof_original_name
            ?: basename((string) $customer->id_proof_file_path)
            ?: ('customer-proof-' . $customer->id);

        $headers = [];
        $mimeType = Storage::disk($disk)->mimeType($customer->id_proof_file_path);

        if (is_string($mimeType) && $mimeType !== '') {
            $headers['Content-Type'] = $mimeType;
        }

        return Storage::disk($disk)->download($customer->id_proof_file_path, $filename, $headers);
    }

    public function edit($id)
    {
        $customer = Customer::where('organization_id', $this->orgId())->findOrFail($id);
        $this->authorize('update', $customer);
        return view('customers.edit', array_merge($this->filterOptions(), compact('customer')));
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::where('organization_id', $this->orgId())->findOrFail($id);
        $this->authorize('update', $customer);

        $validated = $this->validateCustomer($request, false, $customer);

        $customer->update($this->customerPayload($validated, false));
        $this->syncCustomerUploads($request, $customer);

        return redirect()->route('customers.index')
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy($id)
    {
        $customer = Customer::where('organization_id', $this->orgId())->findOrFail($id);
        $this->authorize('delete', $customer);

        $dependencyLabels = $this->customerDeletionDependencies($customer);

        if (!empty($dependencyLabels)) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this customer because it is linked to ' . implode(', ', $dependencyLabels) . '.');
        }

        $this->deleteCustomerRecord($customer);

        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }

    public function bulkDelete(Request $request)
    {
        $customerIds = $this->selectedCustomerIds($request);
        $customers = Customer::query()
            ->where('organization_id', $this->orgId())
            ->whereIn('id', $customerIds)
            ->get();

        $deleted = 0;
        $blocked = [];

        foreach ($customers as $customer) {
            $dependencies = $this->customerDeletionDependencies($customer);

            if (!empty($dependencies)) {
                $blocked[] = $customer->name . ' (' . implode(', ', $dependencies) . ')';
                continue;
            }

            $this->deleteCustomerRecord($customer);
            $deleted++;
        }

        $message = $deleted . ' customer' . ($deleted === 1 ? '' : 's') . ' deleted.';

        if (!empty($blocked)) {
            return redirect()
                ->route('customers.index')
                ->with('error', $message . ' Skipped linked customers: ' . implode('; ', array_slice($blocked, 0, 5)) . (count($blocked) > 5 ? '; and more.' : '.'));
        }

        return redirect()
            ->route('customers.index')
            ->with('success', $message);
    }

    public function quickStore(Request $request)
    {
        if ($existingCustomer = $this->existingQuickCustomer($request)) {
            return response()->json([
                'message' => 'Customer already exists. Reusing existing customer.',
                'customer' => $this->quickCustomerResponsePayload($existingCustomer->fresh()),
            ]);
        }

        $validated = $this->validateCustomer($request, true);

        $customer = Customer::create($this->customerPayload($validated));
        $this->syncCustomerUploads($request, $customer);
        $customer->refresh();

        return response()->json([
            'message' => 'Customer created successfully.',
            'customer' => $this->quickCustomerResponsePayload($customer),
        ]);
    }

    private function existingQuickCustomer(Request $request): ?Customer
    {
        $normalizedPhone = PhoneNumber::normalize(
            (string) $request->input('phone', ''),
            $request->input('phone_country_code')
        );
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($normalizedPhone) {
            return Customer::query()
                ->where('organization_id', $this->orgId())
                ->where('phone', $normalizedPhone)
                ->first();
        }

        if ($email !== '') {
            return Customer::query()
                ->where('organization_id', $this->orgId())
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        return null;
    }

    private function quickCustomerResponsePayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->displayName(),
            'customer_type' => $customer->normalizedCustomerType(),
            'salutation' => Customer::hasSalutationColumn() ? $customer->salutation : null,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'company_name' => $customer->company_name,
            'contact_name' => Customer::hasContactNameColumn() ? $customer->contact_name : null,
            'phone' => $customer->phone,
            'phone_country_code' => PhoneNumber::countryCode($customer->phone),
            'whatsapp_number' => Customer::hasWhatsappNumberColumn() ? $customer->whatsapp_number : null,
            'whatsapp_number_country_code' => Customer::hasWhatsappNumberColumn() ? PhoneNumber::countryCode($customer->whatsapp_number) : null,
            'email' => $customer->email,
            'address' => $customer->address,
            'city' => $customer->city,
            'state' => $customer->state,
            'pincode' => $customer->pincode,
            'map_location_text' => Customer::hasMapLocationTextColumn() ? $customer->map_location_text : null,
            'map_location_url' => Customer::hasMapLocationUrlColumn() ? $customer->map_location_url : null,
        ];
    }

    private function validateCustomer(Request $request, bool $quickMode = false, ?Customer $existingCustomer = null): array
    {
        $routeCustomer = $request->route('customer');
        $customerId = $existingCustomer?->id
            ?? ($routeCustomer instanceof Customer ? $routeCustomer->id : null)
            ?? ($routeCustomer ? (int) $routeCustomer : null)
            ?? ($request->route('id') ? (int) $request->route('id') : null);

        $validated = $request->validate(
            CustomerProfileSupport::validationRules(
                Customer::hasWhatsappNumberColumn(),
                true,
                (int) $this->orgId(),
                $customerId
            )
        );

        if (!empty($validated['state']) && !in_array($validated['state'], Customer::indianStates(), true)) {
            throw ValidationException::withMessages([
                'state' => ['Please choose a valid Indian state or union territory.'],
            ]);
        }

        return CustomerProfileSupport::validateIdentity($validated, $quickMode);
    }

    private function customerPayload(array $validated, bool $includeOrganization = true): array
    {
        $payload = CustomerProfileSupport::payload($validated);

        if (!$includeOrganization) {
            unset($payload['organization_id']);

            return $payload;
        }

        return array_merge($payload, [
            'organization_id' => $this->orgId(),
        ]);
    }

    private function syncCustomerUploads(Request $request, Customer $customer): void
    {
        if (!$request->hasFile('id_proof_file') || !Customer::hasIdProofFilePathColumn()) {
            return;
        }

        $this->deleteCustomerProofFile($customer);

        $file = $request->file('id_proof_file');
        $path = $file->store('customer-id-proofs', 'local');

        $updatePayload = [
            'id_proof_file_path' => $path,
        ];

        if (Customer::hasIdProofOriginalNameColumn()) {
            $updatePayload['id_proof_original_name'] = $file->getClientOriginalName();
        }

        $customer->forceFill($updatePayload)->save();
    }
}
