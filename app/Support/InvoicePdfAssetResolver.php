<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class InvoicePdfAssetResolver
{
    public function logoRelativePath(): string
    {
        $configured = collect((array) config('pdf.optimized_assets.logo', [
            'images/invoice-logo.png',
            'images/prime-healers-logo.png',
        ]));

        foreach ($configured as $relativePath) {
            $path = trim((string) $relativePath);

            if ($path !== '' && is_file(public_path($path))) {
                return ltrim(str_replace('\\', '/', $path), '/');
            }
        }

        return 'images/prime-healers-logo.png';
    }

    public function logoBrowserUrl(): string
    {
        return asset($this->logoRelativePath());
    }

    public function logoDataUri(): ?string
    {
        return $this->publicDataUri($this->logoRelativePath());
    }

    public function qrDataUri(?string $relativePath, bool $forBulk = false): ?string
    {
        if (!config('pdf.show_qr', true)) {
            return null;
        }

        if ($forBulk && !config('pdf.show_qr_in_bulk', false)) {
            return null;
        }

        return $this->storageDataUri($relativePath, 'qr');
    }

    public function signatureDataUri(?string $relativePath): ?string
    {
        return $this->storageDataUri($relativePath, 'signature');
    }

    public function publicDataUri(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        $absolutePath = public_path(ltrim($relativePath, '/'));

        return $this->dataUriForPath($absolutePath, 'public');
    }

    public function storageDataUri(?string $relativePath, string $kind = 'image'): ?string
    {
        if (!$relativePath) {
            return null;
        }

        $absolutePath = public_path('storage/' . ltrim($relativePath, '/'));

        return $this->dataUriForPath($absolutePath, $kind);
    }

    private function dataUriForPath(string $absolutePath, string $kind): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        if ($this->shouldSkipOversizedAsset($absolutePath, $kind)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'image/png';
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        return 'data:' . ($mime ?: 'image/png') . ';base64,' . base64_encode($contents);
    }

    private function shouldSkipOversizedAsset(string $absolutePath, string $kind): bool
    {
        if (!config('pdf.optimize_images', true)) {
            return false;
        }

        $maxImageKb = (int) config('pdf.max_image_kb', 100);

        if ($maxImageKb <= 0) {
            return false;
        }

        $size = @filesize($absolutePath);

        if (!is_int($size) || $size <= ($maxImageKb * 1024)) {
            return false;
        }

        Log::warning('invoice_pdf_asset_skipped', [
            'kind' => $kind,
            'path' => $absolutePath,
            'size_bytes' => $size,
            'max_image_kb' => $maxImageKb,
        ]);

        return true;
    }
}
