<?php

namespace Tests\Unit;

use App\Support\SafeCsv;
use PHPUnit\Framework\TestCase;

class SafeCsvTest extends TestCase
{
    private function roundTrip(array $values): array
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsInt(SafeCsv::row($stream, $values));
        rewind($stream);
        $row = fgetcsv($stream, null, ',', '"', '');
        $this->assertFalse(fgetcsv($stream, null, ',', '"', ''));
        fclose($stream);

        return $row;
    }

    public function test_formula_and_control_leading_text_remains_inert_after_csv_round_trip(): void
    {
        $values = [
            '=HYPERLINK("https://example.test","open")', '+SUM(1,2)', '-2+3', '@SUM(A1:A2)',
            "\t=1+1", "\r=1+1", "\nplain text", "\0=1+1", " \tplain text", '   =SUM(A1:A2)',
            "\u{FEFF}=SUM(A1:A2)", "\u{200B}@SUM(A1:A2)", "\u{00A0}+SUM(1,2)", '＝SUM(A1:A2)',
            "=SUM(1)\xFF",
        ];
        $this->assertSame(array_map(fn ($value) => "'" . $value, $values), $this->roundTrip($values));
    }

    public function test_numbers_headers_and_normal_text_preserve_export_values(): void
    {
        $values = ['Customer', 'Amount', 42, -12, 12.5, '00123', '-12.50', '+919876543210', '.50', '-1.5e+2',
            '', null, true, false, 'Asha Devi', "O'Brien", "'=SUM(1,2)", '  a normal label',
            'Comma, quote " and slash \\ remain one cell', "Normal\nmultiline text"];
        $expected = ['Customer', 'Amount', '42', '-12', '12.5', '00123', '-12.50', '+919876543210', '.50', '-1.5e+2',
            '', '', '1', '', 'Asha Devi', "O'Brien", "'=SUM(1,2)", '  a normal label',
            'Comma, quote " and slash \\ remain one cell', "Normal\nmultiline text"];
        $this->assertSame($expected, $this->roundTrip($values));
    }

    public function test_stringable_user_text_is_protected_as_a_single_field(): void
    {
        $text = new class implements \Stringable {
            public function __toString(): string { return '=SUM(1,2)'; }
        };
        $this->assertSame(["'=SUM(1,2)"], $this->roundTrip([$text]));
    }
}
