<?php

namespace App\Support;

final class SafeCsv
{
    /** Write an RFC 4180 row while keeping untrusted text inert in spreadsheets. */
    public static function row($stream, array $fields): int|false
    {
        return fputcsv($stream, array_map(self::cell(...), $fields), ',', '"', '');
    }

    private static function cell(mixed $value): mixed
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Signed decimals/exponents are numbers, while expressions such as +SUM()
        // remain text. Do not treat control-prefixed numbers as numeric cells.
        if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/D', $value)) {
            return $value;
        }

        // Check past spaces/invisible prefixes, including BOM and direction marks.
        $dangerous = preg_match('/\A[\p{Z}\s\p{Cf}]*(?:[=+\-@＝＋－＠]|\p{Cc}|\p{Cf})/u', $value);
        if ($dangerous === false) {
            // Invalid UTF-8 still receives the ASCII formula/control protection.
            $dangerous = preg_match('/\A[\x00-\x20]*[=+\-@\x00-\x1F\x7F]/', $value);
        }

        return $dangerous ? "'" . $value : $value;
    }
}
