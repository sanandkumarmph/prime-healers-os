<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

class PdfRuntimeConfigurationTest extends TestCase
{
    private function withPdfEnvironment(array $values, callable $check): void
    {
        $repository = Env::getRepository();
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = $repository->get($key);
            $this->assertTrue($repository->set($key, $value));
        }

        try {
            $check(require config_path('pdf.php'));
        } finally {
            foreach ($previous as $key => $value) {
                $value === null ? $repository->clear($key) : $repository->set($key, $value);
            }
        }
    }

    public function test_short_runtime_root_applies_to_all_linux_runtime_directories(): void
    {
        $this->withPdfEnvironment([
            'PDF_RUNTIME_PATH' => '/tmp/phos-pdf-fixture',
            'PDF_TEMP_PATH' => '',
            'PDF_USER_DATA_DIR' => '',
        ], function (array $pdf): void {
            $this->assertSame('/tmp/phos-pdf-fixture/tmp', $pdf['temp_path']);
            $this->assertSame('/tmp/phos-pdf-fixture/profile', $pdf['user_data_dir']);
            foreach ($pdf['environment']['linux'] as $path) {
                $this->assertStringStartsWith('/tmp/phos-pdf-fixture/', $path);
            }
            foreach (['TMPDIR', 'TMP', 'TEMP'] as $key) {
                $this->assertSame($pdf['temp_path'], $pdf['environment']['linux'][$key]);
            }
        });
    }

    public function test_explicit_temp_and_profile_paths_are_preserved(): void
    {
        $this->withPdfEnvironment([
            'PDF_RUNTIME_PATH' => '/tmp/phos-pdf-fixture/',
            'PDF_TEMP_PATH' => '/tmp/phos-temp-fixture',
            'PDF_USER_DATA_DIR' => '/tmp/phos-profile-fixture',
        ], function (array $pdf): void {
            $this->assertSame('/tmp/phos-pdf-fixture', $pdf['runtime_path']);
            $this->assertSame('/tmp/phos-temp-fixture', $pdf['temp_path']);
            $this->assertSame('/tmp/phos-profile-fixture', $pdf['user_data_dir']);
            foreach (['TMPDIR', 'TMP', 'TEMP'] as $key) {
                $this->assertSame('/tmp/phos-temp-fixture', $pdf['environment']['linux'][$key]);
            }
        });
    }

    public function test_blank_runtime_settings_keep_the_existing_storage_defaults(): void
    {
        $this->withPdfEnvironment([
            'PDF_RUNTIME_PATH' => '',
            'PDF_TEMP_PATH' => '',
            'PDF_USER_DATA_DIR' => '',
        ], function (array $pdf): void {
            $this->assertSame(storage_path('app/pdf-runtime'), $pdf['runtime_path']);
            $this->assertSame(storage_path('app/pdf-runtime/tmp'), $pdf['temp_path']);
            $this->assertSame(storage_path('app/pdf-runtime/profile'), $pdf['user_data_dir']);
        });
    }
}
