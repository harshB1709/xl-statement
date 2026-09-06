<?php

namespace App\Services\Excel;

use RuntimeException;
use ZipArchive;

class XlsxWriter
{
    /** @var array<string, array{headers: list<string>, rows: list<list<int|float|string|null>>, column_widths: list<float>, number_formats: array<int, string>}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $headers
     * @param  list<list<int|float|string|null>>  $rows
     * @param  list<float>  $columnWidths
     * @param  array<int, string>  $numberFormats  0-based column index => format code
     */
    public function addSheet(
        string $name,
        array $headers,
        array $rows,
        array $columnWidths = [],
        array $numberFormats = [],
    ): self {
        $this->sheets[$this->sanitizeSheetName($name)] = [
            'headers' => $headers,
            'rows' => $rows,
            'column_widths' => $columnWidths,
            'number_formats' => $numberFormats,
        ];

        return $this;
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('Cannot write an empty workbook.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create output directory: '.$directory);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open output file for writing. If Excel has it open, close it and retry.');
        }

        $sharedStrings = [];
        $sharedIndex = [];
        $sheetXml = [];
        $styleFormats = [NumberFormats::standard()];
        $formatIndex = [];

        foreach ($this->sheets as $sheet) {
            foreach ($sheet['number_formats'] as $format) {
                if (! isset($formatIndex[$format])) {
                    $formatIndex[$format] = count($styleFormats);
                    $styleFormats[] = $format;
                }
            }
        }

        $sheetId = 1;

        foreach ($this->sheets as $name => $sheet) {
            $sheetXml[$name] = $this->buildSheetXml($sheet, $sharedStrings, $sharedIndex, $formatIndex);
            $sheetId++;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(array_keys($this->sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook(array_keys($this->sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(array_keys($this->sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles($styleFormats));
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings($sharedStrings));

        $index = 1;

        foreach ($sheetXml as $xml) {
            $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', $xml);
            $index++;
        }

        $zip->close();
    }

    /**
     * @param  array{headers: list<string>, rows: list<list<int|float|string|null>>, column_widths: list<float>, number_formats: array<int, string>}  $sheet
     * @param  list<string>  $sharedStrings
     * @param  array<string, int>  $sharedIndex
     * @param  array<string, int>  $formatIndex
     */
    private function buildSheetXml(array $sheet, array &$sharedStrings, array &$sharedIndex, array $formatIndex): string
    {
        $headers = $sheet['headers'];
        $rows = $sheet['rows'];
        $columnCount = count($headers);
        $rowCount = count($rows) + 1;
        $dimension = 'A1:'.$this->columnLetter($columnCount).$rowCount;

        $colsXml = '';

        foreach ($sheet['column_widths'] as $index => $width) {
            $col = $index + 1;
            $colsXml .= '<col min="'.$col.'" max="'.$col.'" width="'.$width.'" customWidth="1"/>';
        }

        $sheetData = '<row r="1">';

        foreach ($headers as $index => $header) {
            $sheetData .= $this->stringCell($this->columnLetter($index + 1).'1', $header, $sharedStrings, $sharedIndex, style: 1);
        }

        $sheetData .= '</row>';

        foreach ($rows as $rowOffset => $row) {
            $rowNumber = $rowOffset + 2;
            $sheetData .= '<row r="'.$rowNumber.'">';

            for ($index = 0; $index < $columnCount; $index++) {
                $value = $row[$index] ?? null;
                $cellRef = $this->columnLetter($index + 1).$rowNumber;
                $format = $sheet['number_formats'][$index] ?? null;
                $style = $format !== null ? ($formatIndex[$format] + 2) : 0;

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $sheetData .= '<c r="'.$cellRef.'" s="'.$style.'"><v>'.$this->xml($this->number($value)).'</v></c>';
                } else {
                    $sheetData .= $this->stringCell($cellRef, (string) $value, $sharedStrings, $sharedIndex, $style);
                }
            }

            $sheetData .= '</row>';
        }

        $autoFilter = 'A1:'.$this->columnLetter($columnCount).$rowCount;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="'.$dimension.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($colsXml !== '' ? '<cols>'.$colsXml.'</cols>' : '')
            .'<sheetData>'.$sheetData.'</sheetData>'
            .'<autoFilter ref="'.$autoFilter.'"/>'
            .'</worksheet>';
    }

    /**
     * @param  list<string>  $sharedStrings
     * @param  array<string, int>  $sharedIndex
     */
    private function stringCell(string $ref, string $value, array &$sharedStrings, array &$sharedIndex, int $style = 0): string
    {
        if (! isset($sharedIndex[$value])) {
            $sharedIndex[$value] = count($sharedStrings);
            $sharedStrings[] = $value;
        }

        return '<c r="'.$ref.'" t="s" s="'.$style.'"><v>'.$sharedIndex[$value].'</v></c>';
    }

    /**
     * @param  list<string>  $sheetNames
     */
    private function contentTypes(array $sheetNames): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        foreach (array_keys($sheetNames) as $index) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  list<string>  $sheetNames
     */
    private function workbook(array $sheetNames): string
    {
        $sheets = '';

        foreach ($sheetNames as $index => $name) {
            $sheets .= '<sheet name="'.$this->xml($name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'
            .'</workbook>';
    }

    /**
     * @param  list<string>  $sheetNames
     */
    private function workbookRels(array $sheetNames): string
    {
        $rels = '';

        foreach (array_keys($sheetNames) as $index) {
            $rels .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        $rels .= '<Relationship Id="rId'.(count($sheetNames) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '<Relationship Id="rId'.(count($sheetNames) + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>';
    }

    /**
     * @param  list<string>  $formats
     */
    private function styles(array $formats): string
    {
        $numFmts = '';
        $cellXfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>';

        foreach ($formats as $index => $format) {
            $numFmtId = 164 + $index;
            $numFmts .= '<numFmt numFmtId="'.$numFmtId.'" formatCode="'.$this->xml($format).'"/>';
            $cellXfs .= '<xf numFmtId="'.$numFmtId.'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="'.count($formats).'">'.$numFmts.'</numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9E2F3"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.(2 + count($formats)).'">'.$cellXfs.'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function sharedStrings(array $sharedStrings): string
    {
        $items = '';

        foreach ($sharedStrings as $string) {
            $items .= '<si><t>'.$this->xml($string).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">'
            .$items
            .'</sst>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            $name = 'Sheet';
        }

        return mb_substr($name, 0, 31);
    }

    private function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
