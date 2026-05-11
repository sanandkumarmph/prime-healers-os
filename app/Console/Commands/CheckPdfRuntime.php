<?php

namespace App\Console\Commands;

use App\Support\PdfBrowsershotConfigurator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Browsershot\Browsershot;

class CheckPdfRuntime extends Command
{
    protected $signature = 'rentnexis:check-pdf-runtime';

    protected $description = 'Check Node, Puppeteer, and browser runtime readiness for invoice PDF generation.';

    public function handle(): int
    {
        $configurator = app(PdfBrowsershotConfigurator::class);
        $nodeBinary = (string) config('pdf.node_binary', 'node');
        $nodeModulePath = (string) config('pdf.node_module_path', base_path('node_modules'));
        $configuredBrowserPath = trim((string) config('pdf.browser_path', ''));
        $disableSandbox = config('pdf.disable_sandbox');
        $tempPath = $configurator->tempPath();
        $userDataDir = $configurator->userDataDir();
        $environmentOptions = $configurator->environmentOptions();
        $chromiumArguments = $configurator->chromiumArguments();

        $checks = [];

        $checks[] = $this->checkNodeBinaryConfigured($nodeBinary);
        $checks[] = $this->checkNodeBinaryExecutable($nodeBinary, $tempPath);
        $checks[] = $this->checkNodeModulesPath($nodeModulePath);
        $checks[] = $this->checkNodeDependencyPath($nodeModulePath, 'puppeteer');
        $checks[] = $this->checkNodeDependencyPath($nodeModulePath, 'puppeteer-core');
        $checks[] = $this->checkBrowsershotPackage();
        $checks[] = $this->checkWritableRuntimeDirectory('PDF temp path', $tempPath);
        $checks[] = $this->checkWritableRuntimeDirectory('PDF user data dir', $userDataDir);

        if ($configuredBrowserPath !== '') {
            $checks[] = $this->checkConfiguredBrowserPath($configuredBrowserPath);
        } else {
            $checks[] = $this->checkFallbackBrowserPaths();
        }

        $checks[] = $this->checkDisableSandboxFlag($disableSandbox);
        $checks[] = $this->checkEnvironmentOptions($environmentOptions);
        $checks[] = $this->checkChromiumArguments($chromiumArguments);

        $rows = collect($checks)->map(fn (array $check) => [
            $check['status'],
            $check['check'],
            $check['details'],
        ])->all();

        $this->info('Rentnexis PDF runtime readiness');
        $this->newLine();
        $this->table(['Status', 'Check', 'Details'], $rows);

        $failCount = collect($checks)->where('status', 'FAIL')->count();
        $warnCount = collect($checks)->where('status', 'WARN')->count();

        $this->line('Summary: '
            . collect([
                'PASS' => collect($checks)->where('status', 'PASS')->count(),
                'WARN' => $warnCount,
                'FAIL' => $failCount,
            ])->map(fn (int $count, string $label) => "{$label}={$count}")
                ->implode(', ')
        );

        if ($failCount > 0) {
            $this->warn('PDF runtime is not ready. Fix FAIL items before relying on invoice PDF generation.');

            return self::FAILURE;
        }

        if ($warnCount > 0) {
            $this->warn('PDF runtime is usable with warnings. Review WARN items before UAT deployment.');

            return self::SUCCESS;
        }

        $this->info('PDF runtime looks ready for invoice generation.');

        return self::SUCCESS;
    }

    private function checkNodeBinaryConfigured(string $nodeBinary): array
    {
        if (trim($nodeBinary) === '') {
            return $this->result('FAIL', 'Node binary configured', 'PDF_NODE_BINARY is empty.');
        }

        return $this->result('PASS', 'Node binary configured', "Using configured binary: {$nodeBinary}");
    }

    private function checkNodeBinaryExecutable(string $nodeBinary, string $workingDirectory): array
    {
        try {
            $process = Process::timeout(10);

            if (is_dir($workingDirectory)) {
                $process = $process->path($workingDirectory);
            }

            $result = $process->run([$nodeBinary, '--version']);
        } catch (\Throwable $exception) {
            return $this->result('FAIL', 'Node command executable', $exception->getMessage());
        }

        if (! $result->successful()) {
            $message = trim($result->errorOutput()) ?: trim($result->output()) ?: 'Command returned a non-zero exit code.';

            return $this->result('FAIL', 'Node command executable', $message);
        }

        $version = trim($result->output()) ?: 'Command executed successfully.';

        return $this->result('PASS', 'Node command executable', $version);
    }

    private function checkNodeModulesPath(string $nodeModulePath): array
    {
        if (! File::isDirectory($nodeModulePath)) {
            return $this->result('FAIL', 'Node modules path exists', "Configured path not found: {$nodeModulePath}");
        }

        return $this->result('PASS', 'Node modules path exists', $nodeModulePath);
    }

    private function checkNodeDependencyPath(string $nodeModulePath, string $packageName): array
    {
        $packagePath = rtrim($nodeModulePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $packageName;

        if (! File::isDirectory($packagePath)) {
            return $this->result('FAIL', ucfirst($packageName) . ' package path', "Missing package directory: {$packagePath}");
        }

        return $this->result('PASS', ucfirst($packageName) . ' package path', $packagePath);
    }

    private function checkBrowsershotPackage(): array
    {
        if (! class_exists(Browsershot::class)) {
            return $this->result('FAIL', 'Browsershot PHP package', 'Spatie Browsershot class is not available.');
        }

        return $this->result('PASS', 'Browsershot PHP package', Browsershot::class . ' is available.');
    }

    private function checkWritableRuntimeDirectory(string $label, string $path): array
    {
        try {
            File::ensureDirectoryExists($path);
        } catch (\Throwable $exception) {
            return $this->result('FAIL', $label, $exception->getMessage());
        }

        if (! File::isDirectory($path)) {
            return $this->result('FAIL', $label, "Configured path is not a directory: {$path}");
        }

        if (! is_writable($path)) {
            return $this->result('FAIL', $label, "Configured path is not writable: {$path}");
        }

        return $this->result('PASS', $label, $path);
    }

    private function checkConfiguredBrowserPath(string $configuredBrowserPath): array
    {
        if (! File::exists($configuredBrowserPath)) {
            return $this->result(
                'FAIL',
                'Configured browser path',
                "Configured PDF_BROWSER_PATH was not found: {$configuredBrowserPath}"
            );
        }

        return $this->result('PASS', 'Configured browser path', $configuredBrowserPath);
    }

    private function checkFallbackBrowserPaths(): array
    {
        $platform = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };

        $paths = (array) config("pdf.fallback_browser_paths.{$platform}", []);

        foreach ($paths as $path) {
            if (File::exists($path)) {
                return $this->result('PASS', 'Fallback browser path', "Resolved {$platform} browser: {$path}");
            }
        }

        return $this->result(
            'WARN',
            'Fallback browser path',
            'PDF_BROWSER_PATH is not set and no common browser path was found for ' . $platform . '.'
        );
    }

    private function checkDisableSandboxFlag(mixed $disableSandbox): array
    {
        if (! is_bool($disableSandbox)) {
            return $this->result(
                'WARN',
                'PDF disable sandbox flag',
                'pdf.disable_sandbox resolved to a non-boolean value.'
            );
        }

        return $this->result(
            'PASS',
            'PDF disable sandbox flag',
            'disable_sandbox=' . ($disableSandbox ? 'true' : 'false')
        );
    }

    private function checkChromiumArguments(array $chromiumArguments): array
    {
        if ($chromiumArguments === []) {
            return $this->result('PASS', 'Chromium launch arguments', 'No extra Chromium arguments configured.');
        }

        return $this->result(
            'PASS',
            'Chromium launch arguments',
            implode(', ', array_map(fn (string $argument) => '--' . $argument, $chromiumArguments))
        );
    }

    private function checkEnvironmentOptions(array $environmentOptions): array
    {
        if ($environmentOptions === []) {
            return $this->result('PASS', 'PDF environment options', 'No extra browser environment options configured.');
        }

        $summary = collect($environmentOptions)
            ->map(fn (string $value, string $key) => $key . '=' . $value)
            ->implode(', ');

        return $this->result('PASS', 'PDF environment options', $summary);
    }

    private function result(string $status, string $check, string $details): array
    {
        return compact('status', 'check', 'details');
    }
}
