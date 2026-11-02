<?php

namespace Tests\Unit;

use App\Services\ImportService;
use App\Support\SpreadsheetInput;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetInputTest extends TestCase
{
    public function test_xml_rejects_external_entities_including_utf16_without_reading_a_resource(): void
    {
        foreach (['<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///synthetic-secret">]><worksheet>&x;</worksheet>', "\xff\xfe".iconv('UTF-8', 'UTF-16LE', '<!DOCTYPE worksheet><worksheet/>'), '<worksheet><broken></worksheet>'] as $xml) {
            try {
                SpreadsheetInput::xml($xml);
                $this->fail('Untrusted spreadsheet XML was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Spreadsheet', $exception->getMessage());
            }
        }
        $this->assertSame('name', (string) SpreadsheetInput::xml('<worksheet><cell>name</cell></worksheet>')->cell);
    }

    public function test_compressed_oversized_entry_is_refused_before_decompression(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'phos-xlsx-source-');
        $path = tempnam(sys_get_temp_dir(), 'phos-xlsx-');
        try {
            $stream = fopen($source, 'wb');
            ftruncate($stream, SpreadsheetInput::MAX_ENTRY_BYTES + 1);
            fclose($stream);
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($source, 'xl/sharedStrings.xml');
            $zip->close();
            $zip->open($path);
            try {
                SpreadsheetInput::inspect($zip);
                $this->fail('Oversized archive entry accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('permitted size', $exception->getMessage());
            } finally {
                $zip->close();
            }
        } finally {
            unlink($source);
            unlink($path);
        }
    }

    public function test_real_spreadsheet_parser_rejects_extreme_cell_references_and_preserves_ordinary_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phos-xlsx-');
        try {
            foreach (['ZZZZZZZ1', 'ZZZ1', 'A1'] as $reference) {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="'.$reference.'" t="inlineStr"><is><t>Name</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Asha</t></is></c></row></sheetData></worksheet>');
                $zip->close();
                try {
                    $result = (new \ReflectionMethod(ImportService::class, 'parseXlsx'))->invoke(app(ImportService::class), $path);
                    $this->assertSame('A1', $reference);
                    $this->assertSame(['Name'], $result['headers']);
                    $this->assertSame('Asha', array_values($result['rows'][0])[0]);
                } catch (RuntimeException $exception) {
                    $this->assertNotSame('A1', $reference);
                    $this->assertMatchesRegularExpression('/cell reference|512 columns/', $exception->getMessage());
                }
            }
        } finally {
            unlink($path);
        }
    }
}
