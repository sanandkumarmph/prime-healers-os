<?php

namespace App\Http\Controllers;

use App\Models\CustomerPaymentAccount;
use App\Models\CustomerPaymentLink;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\CustomerPayments\CustomerPaymentLinks;
use App\Services\CustomerPayments\PaymentStatusSnapshot;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerPaymentController extends Controller
{
    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->isAdminOperations(), 403);
        $this->authorize('update', $request->user()->organization);
    }

    public function settings(Request $request)
    {
        $organization = $request->user()->organization;
        $this->authorizeSettings($request);

        return redirect()->to(route('organization.settings.edit').'#payments');
    }

    public function saveSettings(Request $request)
    {
        $organization = $request->user()->organization;
        $this->authorizeSettings($request);
        $data = $request->validate(['key_id' => ['required', 'string', 'regex:/^rzp_(test|live)_[A-Za-z0-9]+$/D', 'max:100'],
            'key_secret' => ['required', 'string', 'min:12', 'max:255'], 'current_password' => ['required', 'current_password'],
            'mode' => ['required', Rule::in(['test'])]]);
        if (! str_starts_with($data['key_id'], 'rzp_'.$data['mode'].'_')) {
            throw ValidationException::withMessages(['key_id' => 'The API key must match the selected mode.']);
        }
        DB::transaction(function () use ($organization, $data) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $accounts = CustomerPaymentAccount::where('organization_id', $organization->id)->lockForUpdate()->get();
            if (CustomerPaymentLink::where('organization_id', $organization->id)->open()->exists()) {
                throw ValidationException::withMessages(['key_id' => 'Resolve or cancel open payment links before replacing this account.']);
            }
            $fingerprint = hash('sha256', $data['key_id']);
            if (CustomerPaymentAccount::where('key_fingerprint', $fingerprint)->exists()) {
                throw ValidationException::withMessages(['key_id' => 'This API key has already been configured. Use a newly generated key to replace an account.']);
            }
            CustomerPaymentAccount::whereIn('id', $accounts->pluck('id'))->update(['is_active' => false]);
            CustomerPaymentAccount::create(['organization_id' => $organization->id, 'webhook_token' => (string) Str::uuid(),
                'mode' => $data['mode'], 'key_fingerprint' => $fingerprint, 'key_id' => $data['key_id'], 'key_secret' => $data['key_secret'],
                'webhook_secret' => Str::random(48), 'is_active' => true]);
            ActivityLogger::log('payment_account.configured', $organization, ['mode' => $data['mode']], 'Customer payment account configured.');
        });

        return redirect()->to(route('organization.settings.edit').'#payments')
            ->with('success', 'Razorpay settings saved. Copy the webhook URL and secret below into your Razorpay Test Mode dashboard.');
    }

    private function invoice(Request $request, int $id, bool $write = false): Invoice
    {
        $invoice = Invoice::where('organization_id', $request->user()->organization_id)->findOrFail($id);
        $this->authorize('view', $invoice);
        if ($write) {
            $this->authorize('createForInvoice', [Payment::class, $invoice]);
        }

        return $invoice;
    }

    public function show(Request $request, int $id)
    {
        $invoice = $this->invoice($request, $id);
        $links = CustomerPaymentLink::with(['account', 'receipts'])->where('organization_id', $invoice->organization_id)->where('invoice_id', $invoice->id)->latest()->get();
        $account = CustomerPaymentAccount::where('organization_id', $invoice->organization_id)->where('is_active', true)->first();

        return response()->view('customer-payments.invoice', compact('invoice', 'links', 'account'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, int $id, CustomerPaymentLinks $service)
    {
        $invoice = $this->invoice($request, $id, true);
        $request->validate(['reminders' => ['sometimes', 'boolean']]);
        $service->create($invoice, $request->user(), $request->boolean('reminders'));

        return redirect()->route('customer-payments.invoice', $id)->with('success', 'Payment link created. Copy it below to share with the customer.');
    }

    public function status(Request $request, int $id, PaymentStatusSnapshot $snapshot)
    {
        // Read-only polling uses the same tenant and self-created scope as invoices.
        // Never expose credentials, payment URLs or trigger a gateway write here.
        $invoice = $this->invoice($request, $id);

        return response()->json(['version' => $snapshot->version($invoice)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, int $id, int $linkId, CustomerPaymentLinks $service)
    {
        $invoice = $this->invoice($request, $id, true);
        $link = CustomerPaymentLink::where('organization_id', $invoice->organization_id)->where('invoice_id', $invoice->id)->findOrFail($linkId);
        $data = $request->validate(['action' => ['required', Rule::in(['refresh', 'cancel'])]]);
        if ($data['action'] === 'cancel') {
            $service->cancel($link);
        } else {
            $service->refresh($link);
        }

        return redirect()->route('customer-payments.invoice', $id)->with('success', 'Gateway status checked.');
    }
}
