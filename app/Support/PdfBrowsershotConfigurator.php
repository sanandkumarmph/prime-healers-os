<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

class PdfBrowsershotConfigurator
{
    public function configure(Browsershot $browsershot): Browsershot
    {
        $browsershot->setNodeBinary((string) config('pdf.node_binary', 'node'));
        $browsershot->setCustomTempPath($this->tempPath());
        $browsershot->setOption('headless', true);
        $browsershot->ignoreHttpsErrors();
        $browsershot->dismissDialogs();

        $nodeModulePath = trim((string) config('pdf.node_module_path', ''));
        if ($nodeModulePath !== '') {
            $browsershot->setNodeModulePath($nodeModulePath);
        }

        $browserPath = $this->configuredBrowserPath();
        if ($browserPath) {
            $browsershot->setChromePath($browserPath);
        }

        $browsershot->userDataDir($this->userDataDir());

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

        return $browsershot;
    }

    public function configuredBrowserPath(): ?string
    {
        $configuredPath = trim((string) config('pdf.browser_path', ''));
        if ($configuredPath !== '') {
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

        return array_values(array_unique(array_filter(
            array_map(fn ($argument) => trim((string) $argument), $arguments),
            fn (string $argument) => $argument !== ''
        )));
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
            $configuredPath !== '' ? $configuredPath : storage_path('app/pdf-runtime/tmp')
        );
    }

    public function userDataDir(): string
    {
        $configuredPath = trim((string) config('pdf.user_data_dir', ''));

        return $this->ensureDirectory(
            $configuredPath !== '' ? $configuredPath : storage_path('app/pdf-runtime/profile')
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
        }
    }

    private function ensureDirectory(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('PDF runtime path configuration resolved to an empty directory path.');
        }

        File::ensureDirectoryExists($path);

        return $path;
    }

    private function disableSandbox(): bool
    {
        return (bool) config('pdf.disable_sandbox', DIRECTORY_SEPARATOR !== '\\');
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
