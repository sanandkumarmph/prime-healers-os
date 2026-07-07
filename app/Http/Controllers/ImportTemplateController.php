<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use App\Support\ImportSetupWizard;

class ImportTemplateController extends Controller
{
    private function canAccessDataImport(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() ?? false;
    }

    private function uploadHref(string $key): ?string
    {
        return match ($key) {
            'sales' => route('imports.sales.upload'),
            'opening-balances' => route('imports.opening-balances.upload'),
            default => route('imports.module', $key),
        };
    }

    public function index(ImportService $service, ImportSetupWizard $wizard)
    {
        $this->authorize('access', ImportController::class);
        abort_unless($this->canAccessDataImport(), 403);

        $cards = collect($service->templateCatalog())
            ->map(function (array $template, string $key) {
                $template['key'] = $key;
                $template['download_href'] = route('imports.template', $key);
                $template['upload_href'] = $this->uploadHref($key);

                return $template;
            })
            ->values();

        $setup = $wizard->buildForCatalog($cards, (int) auth()->user()->organization_id);
        $cards = $cards
            ->map(function (array $card) use ($setup) {
                $card['setup'] = $setup['cards'][$card['key']] ?? null;

                return $card;
            })
            ->values();

        return view('import.index', compact('cards', 'setup'));
    }

    public function download(string $template, ImportService $service)
    {
        $this->authorize('access', ImportController::class);
        abort_unless($this->canAccessDataImport(), 403);
        $workbook = $service->templateWorkbook($template);

        return response($workbook['content'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $workbook['filename'] . '"',
        ]);
    }
}
