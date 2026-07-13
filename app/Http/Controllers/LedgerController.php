<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Finance\LedgerService;
use App\Support\InvoicePdfAssetResolver;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerController extends Controller
{
    public function __construct(private readonly LedgerService $ledgerService)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->ledgerService->filtersFromRequest($request);
        $this->authorizeLedgerAccess($filters);

        $organizationId = $this->orgId();
        $statement = $this->ledgerService->statement($organizationId, $filters);
        $filterOptions = $this->ledgerService->filterOptions($organizationId);

        return view('ledger.index', compact('statement', 'filterOptions'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->ledgerService->filtersFromRequest($request);
        $this->authorizeLedgerAccess($filters);

        $statement = $this->ledgerService->statement($this->orgId(), $filters);
        $filename = 'ledger-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($statement) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Date', 'Reference', 'Particulars', 'Debit', 'Credit', 'Running Balance', 'Status']);

            foreach ($statement['entries'] as $entry) {
                fputcsv($output, [
                    $entry['entry_date']->format('Y-m-d'),
                    $entry['reference'],
                    $entry['particulars'],
                    number_format((float) $entry['debit'], 2, '.', ''),
                    number_format((float) $entry['credit'], 2, '.', ''),
                    number_format((float) $entry['running_balance'], 2, '.', ''),
                    $entry['status_label'] ?? $entry['status'],
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function previewPdf(Request $request)
    {
        $filters = $this->ledgerService->filtersFromRequest($request);
        $this->authorizeLedgerAccess($filters);

        return $this->statementPdf($filters)->stream($this->statementFilename(), ['Attachment' => false]);
    }

    public function exportPdf(Request $request)
    {
        $filters = $this->ledgerService->filtersFromRequest($request);
        $this->authorizeLedgerAccess($filters);

        return $this->statementPdf($filters)->download($this->statementFilename());
    }

    private function statementPdf(array $filters)
    {
        $organizationId = $this->orgId();
        $statement = $this->ledgerService->statement($organizationId, $filters);
        $organization = Organization::query()->find($organizationId);
        $pdfAssets = app(InvoicePdfAssetResolver::class);
        $pdfImagesEnabled = $pdfAssets->imagesEnabled();
        $logoDataUri = $pdfAssets->logoDataUri($organization?->logo);
        $signatureDataUri = $pdfAssets->signatureDataUri($organization?->digital_signature);

        return DomPdf::loadView('ledger.pdf', compact(
            'statement',
            'organization',
            'pdfImagesEnabled',
            'logoDataUri',
            'signatureDataUri'
        ))
            ->setPaper('a4', 'portrait');
    }

    private function statementFilename(): string
    {
        return 'statement-of-accounts-' . now()->format('Ymd-His') . '.pdf';
    }

    private function authorizeLedgerAccess(array $filters): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $isRecordLedger = collect([
            $filters['customer_id'] ?? null,
            $filters['business_partner_id'] ?? null,
            $filters['rental_id'] ?? null,
            $filters['sale_id'] ?? null,
            $filters['invoice_id'] ?? null,
        ])->filter()->isNotEmpty();

        if ($isRecordLedger) {
            abort_unless($user->canViewRecordFinance(), 403);
            return;
        }

        abort_unless($user->isSuperAdmin() || $user->canViewFinance(), 403);
    }

    private function orgId(): int
    {
        return (int) Auth::user()->organization_id;
    }
}
