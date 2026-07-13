<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class InvoicePdfAssetResolver
{
    public function __construct(private readonly PdfImageDataUri $images)
    {
    }

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

    public function logoDataUri(?string $organizationLogoPath = null): ?string
    {
        if (!$this->imagesEnabled()) {
            return null;
        }

        if ($organizationLogoPath) {
            $dataUri = $this->storageDataUri($organizationLogoPath, 'logo', false)
                ?? $this->publicDataUri($organizationLogoPath, 'logo', false);

            if ($dataUri) {
                return $dataUri;
            }
        }

        return $this->publicDataUri($this->logoRelativePath(), 'logo');
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
        return $this->storageDataUri($relativePath, 'signature', false);
    }

    public function publicDataUri(?string $relativePath, string $kind = 'public', bool $respectSizeLimit = true): ?string
    {
        if (!$this->imagesEnabled() || !$relativePath) {
            return null;
        }

        $path = trim(str_replace('\\', '/', (string) $relativePath));

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return $this->storageDataUri($path, $kind, $respectSizeLimit);
        }

        $absolutePath = public_path($path);

        return $this->dataUriForPath($absolutePath, $kind, $respectSizeLimit);
    }

    public function storageDataUri(?string $relativePath, string $kind = 'image', bool $respectSizeLimit = true): ?string
    {
        if (!$this->imagesEnabled() || !$relativePath) {
            return null;
        }

        $path = trim(str_replace('\\', '/', (string) $relativePath));

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $absolutePath = $this->resolveConfiguredPath($path);

        if (!$absolutePath) {
            return null;
        }

        if ($respectSizeLimit && $this->shouldSkipOversizedAsset($absolutePath, $kind)) {
            return null;
        }

        return $this->images->fromLocalPath($absolutePath);
    }

    private function dataUriForPath(string $absolutePath, string $kind, bool $respectSizeLimit = true): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        if ($respectSizeLimit && $this->shouldSkipOversizedAsset($absolutePath, $kind)) {
            return null;
        }

        return $this->images->fromLocalPath($absolutePath);
    }

    private function resolveConfiguredPath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = ltrim($path, '/');

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 && is_file($path)) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'public/')) {
            $path = substr($path, strlen('public/'));
        }

        foreach ([
            storage_path('app/public/' . $path),
            public_path('storage/' . $path),
            public_path($path),
        ] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function shouldSkipOversizedAsset(string $absolutePath, string $kind): bool
    {
        if (!config('pdf.optimize_images', true)) {
            return false;
        }

        $maxImageKb = $kind === 'qr'
            ? (int) config('pdf.max_qr_image_kb', config('pdf.max_image_kb', 100))
            : (int) config('pdf.max_image_kb', 100);

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

    public function imagesEnabled(): bool
    {
        return extension_loaded('gd') || extension_loaded('imagick');
    }
}
