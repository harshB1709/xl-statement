<?php

namespace App\Actions;

use App\Data\RawColumn;
use App\Data\RawTable;
use App\Services\Pdf\ExtractedText;
use App\Services\Pdf\PdfTextExtractor;
use App\Services\Table\ColumnBoundaryDetector;
use App\Services\Table\DateLedRowAssembler;
use App\Services\Table\HeaderRowFinder;
use App\Services\Table\LayoutFingerprint;
use App\Services\Table\NoiseFilter;
use App\Services\Table\RowSlicer;
use RuntimeException;

class ExtractStatementTable
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly HeaderRowFinder $headerRowFinder = new HeaderRowFinder,
        private readonly ColumnBoundaryDetector $columnBoundaryDetector = new ColumnBoundaryDetector,
        private readonly RowSlicer $rowSlicer = new RowSlicer,
        private readonly NoiseFilter $noiseFilter = new NoiseFilter,
        private readonly LayoutFingerprint $layoutFingerprint = new LayoutFingerprint,
        private readonly DateLedRowAssembler $dateLedRowAssembler = new DateLedRowAssembler,
    ) {}

    public function handle(string $path, ?string $password = null): RawTable
    {
        $extracted = $this->extractor->extract($path, $password);

        return $this->fromExtractedText($extracted);
    }

    public function fromExtractedText(ExtractedText $extracted): RawTable
    {
        $header = $this->headerRowFinder->find($extracted->pages);

        if ($header === null) {
            throw new RuntimeException('Could not find a transaction table header in '.$extracted->sourceFile);
        }

        $bodyLines = [];
        $pageOfLines = [];
        $preamble = [];

        foreach ($extracted->pages as $pageIndex => $page) {
            $lines = preg_split("/\r\n|\n|\r/", $page) ?: [];
            $start = 0;

            if ($pageIndex === $header['page_index']) {
                $start = $header['line_index'] + 1;
                $preamble = array_slice($lines, 0, $header['line_index']);
            } else {
                // Skip until a line that looks like the repeated header, then take body after it.
                foreach ($lines as $lineIndex => $line) {
                    if ($this->headerRowFinder->scoreLine($line) >= 2) {
                        $start = $lineIndex + 1;
                        break;
                    }
                }
            }

            for ($i = $start; $i < count($lines); $i++) {
                $line = $lines[$i];
                $normalized = strtolower(trim($line));

                if ($normalized === '' || preg_match('/^page\s+\d+\s+of\s+\d+/', $normalized) === 1 || str_contains($normalized, 'computer generated')) {
                    continue;
                }

                $bodyLines[] = $line;
                $pageOfLines[] = $pageIndex + 1;
            }
        }

        $boundaries = $this->columnBoundaryDetector->detect($header['line'], $bodyLines);
        $sliced = $this->rowSlicer->slice($boundaries, $bodyLines);
        $dateLikeBody = $this->dateLedRowAssembler->countDateLikeLines($bodyLines);

        $pageOf = [];
        $keptRows = [];
        $headerCells = array_map(static fn (array $boundary): string => $boundary['header_text'], $boundaries);

        foreach ($sliced as $index => $row) {
            $filtered = $this->noiseFilter->filter([$row], $headerCells);

            if ($filtered === []) {
                continue;
            }

            $keptRows[] = $row;
            $pageOf[] = $pageOfLines[$index] ?? 1;
        }

        $columns = [];

        foreach ($boundaries as $index => $boundary) {
            $samples = [];

            foreach ($keptRows as $row) {
                $value = trim($row[$index] ?? '');

                if ($value === '') {
                    continue;
                }

                $samples[] = $value;

                if (count($samples) >= 5) {
                    break;
                }
            }

            $columns[] = new RawColumn(
                index: $index,
                headerText: $boundary['header_text'],
                xStart: $boundary['x_start'],
                xEnd: $boundary['x_end'],
                sampleValues: $samples,
            );
        }

        $bankName = $this->sniffBankName(implode("\n", $preamble)."\n".$extracted->fullText());

        if ($this->dateLedRowAssembler->shouldUse(count($sliced), $dateLikeBody, $headerCells)) {
            return $this->dateLedRowAssembler->assemble(
                pages: $extracted->pages,
                sourceFile: $extracted->sourceFile,
                bankName: $bankName,
                rawPreamble: implode("\n", $preamble),
                textEngine: $extracted->engine,
            );
        }

        return new RawTable(
            layoutFingerprint: $this->layoutFingerprint->make($headerCells, count($columns), $bankName),
            headerCells: $headerCells,
            columns: $columns,
            rows: $keptRows,
            pageOf: $pageOf,
            sourceFile: $extracted->sourceFile,
            bankName: $bankName,
            rawPreamble: implode("\n", $preamble),
            textEngine: $extracted->engine,
        );
    }

    private function sniffBankName(string $preamble): ?string
    {
        if (preg_match('/\bKKBK\d{4,}/i', $preamble) === 1 || preg_match('/\bKotak Mahindra Bank\b/i', $preamble) === 1 || preg_match('/\bkotak\.bank\.in\b/i', $preamble) === 1) {
            return 'Kotak Mahindra Bank';
        }

        if (preg_match('/\b(HDFC Bank|ICICI Bank|State Bank of India|Axis Bank|Yes Bank|IDFC FIRST Bank|Punjab National Bank|Bank of Baroda|Central Bank of India)\b/i', $preamble, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bSBI\b/', $preamble) === 1) {
            return 'SBI';
        }

        if (preg_match('/\bAxis Account\b/i', $preamble) === 1 || preg_match('/\bUTIB\d{7}\b/', $preamble) === 1) {
            return 'Axis Bank';
        }

        return null;
    }
}
