<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

class PdfBrowsershotConfigurator
{
    private array $renderProfiles = [];

    public function configure(Browsershot $browsershot): Browsershot
    {
        $browsershot->setNodeBinary((string) config('pdf.node_binary', 'node'));
        $browsershot->setCustomTempPath($this->tempPath());
        $browsershot->setOption('headless', true);
        $browsershot->usePipe();
        $browsershot->dismissDialogs();

        $nodeModulePath = trim((string) config('pdf.node_module_path', ''));
        if ($nodeModulePath !== '') {
            $this->requireAbsolutePath($nodeModulePath);
            $browsershot->setNodeModulePath($nodeModulePath);
        }

        $browserPath = $this->configuredBrowserPath();
        if ($browserPath) {
            $browsershot->setChromePath($browserPath);
        }

        $environmentOptions = $this->environmentOptions();
        if ($environmentOptions !== []) {
            $browsershot->setEnvironmentOptions($environmentOptions);
        }

        $chromiumArguments = $this->chromiumArguments();
        if ($chromiumArguments !== []) {
            $browsershot->addChromiumArguments($chromiumArguments);
        }

        $blockedUrls = $this->blockedUrls();
        if ($blockedUrls !== []) {
            $browsershot->blockUrls($blockedUrls);
        }

        if ($this->platformKey() === 'linux') {
            $browsershot->newHeadless();
        }

        if ($this->disableSandbox()) {
            $browsershot->noSandbox();
        }

        // Separate profiles avoid Chromium's process lock when invoices are
        // rendered concurrently. Only this render's profile is cleaned up.
        $profile = $this->ensureDirectory($this->userDataDir().DIRECTORY_SEPARATOR.'p-'.Str::random(16));
        $this->renderProfiles[] = $profile;
        $browsershot->userDataDir($profile);

        return $browsershot;
    }

    public function configuredBrowserPath(): ?string
    {
        $configuredPath = trim((string) config('pdf.browser_path', ''));
        if ($configuredPath !== '') {
            $this->requireAbsolutePath($configuredPath);
            if (is_file($configuredPath) || is_executable($configuredPath)) {
                return $configuredPath;
            }

            throw new RuntimeException(
                'Invoice PDF browser path is configured but not readable. Check PDF_BROWSER_PATH.'
            );
        }

        $candidates = match ($this->platformKey()) {
            'windows' => (array) config('pdf.fallback_browser_paths.windows', []),
            'macos' => (array) config('pdf.fallback_browser_paths.macos', []),
            default => (array) config('pdf.fallback_browser_paths.linux', []),
        };

        foreach ($candidates as $candidate) {
            if (is_file($candidate) || is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function chromiumArguments(): array
    {
        $arguments = array_merge(
            (array) config('pdf.chromium_arguments.common', []),
            (array) config('pdf.chromium_arguments.' . $this->platformKey(), [])
        );

        if ($this->platformKey() === 'linux' && $this->disableSandbox()) {
            $arguments[] = 'no-sandbox';
            $arguments[] = 'disable-setuid-sandbox';
        }

        $arguments = array_values(array_unique(array_filter(
            array_map(fn ($argument) => trim((string) $argument), $arguments),
            fn (string $argument) => $argument !== ''
        )));

        if (! $this->disableSandbox()) {
            // Legacy Linux flags cannot survive an explicit request for the
            // Chromium sandbox: single-process/no-zygote also prevent its startup.
            $arguments = array_values(array_filter($arguments, function (string $argument): bool {
                $name = strtolower(explode('=', ltrim($argument, '-'), 2)[0]);

                return ! str_contains($name, 'sandbox')
                    && ! in_array($name, ['single-process', 'no-zygote'], true);
            }));
        }

        return $arguments;
    }

    public function environmentOptions(): array
    {
        $configured = (array) config('pdf.environment.' . $this->platformKey(), []);

        $resolved = [];

        foreach ($configured as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);

            if ($key === '' || $value === '') {
                continue;
            }

            $resolved[$key] = $this->ensureDirectory($value);
        }

        return $resolved;
    }

    public function blockedUrls(): array
    {
        $urls = [
            asset('images/prime-healers-favicon.png'),
            asset('images/rentnexis-favicon.png'),
            asset('favicon.ico'),
        ];

        return array_values(array_unique(array_filter(
            array_map(fn ($url) => trim((string) $url), $urls),
            fn (string $url) => $url !== '' && Str::startsWith($url, ['http://', 'https://'])
        )));
    }

    public function tempPath(): string
    {
        $configuredPath = trim((string) config('pdf.temp_path', ''));

        return $this->ensureDirectory(
            $configuredPath !== '' ? $configuredPath : $this->runtimePath('tmp')
        );
    }

    public function userDataDir(): string
    {
        $configuredPath = trim((string) config('pdf.user_data_dir', ''));

        return $this->ensureDirectory(
            $configuredPath !== '' ? $configuredPath : $this->runtimePath('profile')
        );
    }

    public function runInPdfWorkingDirectory(callable $callback): mixed
    {
        $workingDirectory = $this->tempPath();
        $previousDirectory = getcwd();

        if (! @chdir($workingDirectory)) {
            throw new RuntimeException("Unable to switch into the PDF runtime working directory: {$workingDirectory}");
        }

        try {
            return $callback();
        } finally {
            if (is_string($previousDirectory) && $previousDirectory !== '' && is_dir($previousDirectory)) {
                @chdir($previousDirectory);
            }
            foreach ($this->renderProfiles as $profile) {
                File::deleteDirectory($profile);
            }
            $this->renderProfiles = [];
        }
    }

    private function ensureDirectory(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('PDF runtime path configuration resolved to an empty directory path.');
        }

        $this->requireAbsolutePath($path);
        $current = $path;
        while (true) {
            if (is_link($current)) {
                throw new RuntimeException('PDF runtime directories cannot contain symbolic links.');
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        File::ensureDirectoryExists($path, 0700);
        if (! is_dir($path) || ! is_writable($path)) {
            throw new RuntimeException('PDF runtime directory must be writable by the website account.');
        }

        return $path;
    }

    private function runtimePath(string $child): string
    {
        return rtrim((string) (config('pdf.runtime_path') ?: storage_path('app/pdf-runtime')), '/\\')
            .DIRECTORY_SEPARATOR.$child;
    }

    private function requireAbsolutePath(string $path): void
    {
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        if (! $absolute || preg_match('/[\x00-\x1f\x7f]/', $path)
            || in_array('..', preg_split('~[\\\\/]~', $path), true)) {
            throw new RuntimeException('PDF paths must be absolute and cannot contain traversal or control characters.');
        }
    }

    private function disableSandbox(): bool
    {
        return (bool) config('pdf.disable_sandbox', false);
    }

    private function platformKey(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
    }
}
