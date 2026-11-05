<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class XlsxWorkbook
{
    public function build(array $sheets, string $title, bool $numericCells = false): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'phos-xlsx-');
        $zip = new ZipArchive();

        if ($tmpPath === false) {
            throw new RuntimeException('Unable to create spreadsheet.');
        }

        try {
            if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to open spreadsheet archive.');
            }

            $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->xlsxRootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook($sheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelationships(count($sheets)));
            $zip->addFromString('docProps/core.xml', $this->xlsxCoreProperties($title));
            $zip->addFromString('docProps/app.xml', $this->xlsxAppProperties($sheets));

            $sheetIndex = 1;
            foreach ($sheets as $title => $rows) {
                $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $this->xlsxWorksheet($rows, $numericCells));
                $sheetIndex++;
            }

            if (!$zip->close() || ($content = file_get_contents($tmpPath)) === false) {
                throw new RuntimeException('Unable to save spreadsheet.');
            }

            return $content;
        } finally {
            unlink($tmpPath);
        }
    }


    private function xlsxContentTypes(int $sheetCount): string
    {
        $sheetOverrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $sheetOverrides
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbook(array $sheets): string
    {
        $sheetNodes = '';
        $sheetId = 1;
        foreach (array_keys($sheets) as $title) {
            $sheetNodes .= '<sheet name="' . e($title) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
            $sheetId++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetNodes . '</sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelationships(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function xlsxCoreProperties(string $title): string
    {
        $timestamp = now()->toAtomString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>'.e($title).'</dc:title>'
            . '<dc:creator>Prime Healers OS</dc:creator>'
            . '<cp:lastModifiedBy>Prime Healers OS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function xlsxAppProperties(array $sheets): string
    {
        $titles = implode('', array_map(fn ($title) => '<vt:lpstr>'.e($title).'</vt:lpstr>', array_keys($sheets)));
        $count = count($sheets);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Prime Healers OS</Application>'
            . '<TitlesOfParts><vt:vector size="'.$count.'" baseType="lpstr">'.$titles.'</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private function xlsxWorksheet(array $rows, bool $numericCells): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cellXml = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = self::columnReference($columnIndex) . ($rowIndex + 1);
                if ($numericCells && (is_int($value) || is_float($value))) {
                    $cellXml .= '<c r="'.$ref.'" t="n"><v>'.json_encode($value, JSON_THROW_ON_ERROR).'</v></c>';
                    continue;
                }
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
                $cellXml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$safeValue.'</t></is></c>';
            }
            $xmlRows .= '<row r="'.($rowIndex + 1).'">'.$cellXml.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<sheetData>'.$xmlRows.'</sheetData>'
            . '</worksheet>';
    }

    public static function columnReference(int $index): string
    {
        $index++;
        $reference = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $reference = chr(65 + $mod) . $reference;
            $index = intdiv($index - 1, 26);
        }

        return $reference;
    }
}
