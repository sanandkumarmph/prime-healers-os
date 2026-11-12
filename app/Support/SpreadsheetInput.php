<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class SpreadsheetInput
{
    public const MAX_ENTRY_BYTES = 32 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024;

    public static function inspect(ZipArchive $archive): void
    {
        if ($archive->numFiles > 2000) {
            throw new RuntimeException('Spreadsheet has too many archive entries. Split it into smaller files.');
        }
        $total = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            if (!$entry || ($entry['size'] ?? -1) < 0 || $entry['size'] > self::MAX_ENTRY_BYTES) {
                throw new RuntimeException('Spreadsheet expands beyond the permitted size. Split it into smaller files.');
            }
            $total += $entry['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Spreadsheet expands beyond the permitted size. Split it into smaller files.');
            }
        }
    }

    public static function entry(ZipArchive $archive, string $name): string|false
    {
        $value = $archive->getFromName($name, self::MAX_ENTRY_BYTES + 1);
        if ($value !== false && strlen($value) > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('Spreadsheet entry exceeds the permitted size.');
        }

        return $value;
    }

    public static function xml(string $xml): SimpleXMLElement
    {
        if (strlen($xml) > self::MAX_ENTRY_BYTES || str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new RuntimeException('Spreadsheet XML contains unsupported declarations or exceeds the permitted size.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($document === false) {
                throw new RuntimeException('Spreadsheet contains malformed XML.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
