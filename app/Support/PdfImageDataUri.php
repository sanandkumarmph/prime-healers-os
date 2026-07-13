<?php

namespace App\Support;

class PdfImageDataUri
{
    /**
     * Convert a local readable image path into a DOMPDF-safe data URI.
     */
    public function fromLocalPath(?string $absolutePath): ?string
    {
        if (!$absolutePath || !is_string($absolutePath)) {
            return null;
        }

        $realPath = realpath($absolutePath);

        if (!$realPath || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $mime = $this->mimeType($realPath);

        if (!$mime || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($realPath);

        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    public function fromConfiguredPath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = ltrim($path, '/');

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '//')) {
            $dataUri = $this->fromLocalPath($path);

            if ($dataUri) {
                return $dataUri;
            }
        }

        $storageDataUri = $this->fromPublicStorage($path);

        if ($storageDataUri) {
            return $storageDataUri;
        }

        return $this->fromPublicAsset($path);
    }

    public function fromPublicAsset(?string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        if (!$relativePath) {
            return null;
        }

        return $this->fromLocalPath(public_path($relativePath));
    }

    public function fromPublicStorage(?string $relativePath): ?string
    {
        $relativePath = $this->normalizeStoragePath($relativePath);

        if (!$relativePath) {
            return null;
        }

        foreach ([
            storage_path('app/public/' . $relativePath),
            public_path('storage/' . $relativePath),
        ] as $candidate) {
            $dataUri = $this->fromLocalPath($candidate);

            if ($dataUri) {
                return $dataUri;
            }
        }

        return null;
    }

    public function firstAvailable(array $paths): ?string
    {
        foreach ($paths as $path) {
            $dataUri = $this->fromLocalPath(is_string($path) ? $path : null);

            if ($dataUri) {
                return $dataUri;
            }
        }

        return null;
    }

    private function normalizeRelativePath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path));
        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }

    private function normalizeStoragePath(?string $path): ?string
    {
        $path = $this->normalizeRelativePath($path);

        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'public/')) {
            $path = substr($path, strlen('public/'));
        }

        return $path !== '' ? $path : null;
    }

    private function mimeType(string $absolutePath): ?string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($absolutePath);

            if (is_string($mime) && $mime !== '') {
                return $mime === 'image/x-png' ? 'image/png' : $mime;
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($absolutePath);

            if (is_string($mime) && $mime !== '') {
                return $mime === 'image/x-png' ? 'image/png' : $mime;
            }
        }

        return null;
    }
}