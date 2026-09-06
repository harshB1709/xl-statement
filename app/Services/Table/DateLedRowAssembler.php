<?php

namespace App\Services\Table;

use App\Data\RawColumn;
use App\Data\RawTable;

/**
 * Builds a clean RawTable from poorly aligned PDF text (common with smalot
 * on Axis/HDFC/IDFC-style statements, and Papier's glued-token output) by
 * scanning date-led transaction blocks instead of fixed-width column slicing.
 */
class DateLedRowAssembler
{
    private const MONTHS = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

    /**
     * @param  list<string>  $pages
     */
    public function assemble(array $pages, string $sourceFile, ?string $bankName = null, string $rawPreamble = ''): RawTable
    {
        $lines = [];
        $pageOf = [];

        foreach ($pages as $pageIndex => $page) {
            foreach (preg_split("/\r\n|\n|\r/", $page) ?: [] as $line) {
                $lines[] = $this->unglueTokens($line);
                $pageOf[] = $pageIndex + 1;
            }
        }

        $rows = [];
        $rowPages = [];
        $current = null;
        $currentPage = 1;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || $this->isNoise($trimmed)) {
                if ($current !== null && $this->isHardStop($trimmed)) {
                    $built = $this->finalize($current);

                    if ($built !== null) {
                        $rows[] = $built;
                        $rowPages[] = $currentPage;
                    }

                    $current = null;
                }

                continue;
            }

            if ($this->isDateLed($trimmed)) {
                if ($this->isStatementPeriod($trimmed)) {
                    continue;
                }

                if ($current !== null) {
                    $built = $this->finalize($current);

                    if ($built !== null) {
                        $rows[] = $built;
                        $rowPages[] = $currentPage;
                    }
                }

                $currentPage = $pageOf[$index] ?? 1;
                $date = $this->extractDate($trimmed);
                $rest = $this->stripLeadingDates($trimmed);

                $current = [
                    'date' => $date,
                    'description' => $rest,
                    'amount_line' => null,
                    'marker' => null,
                ];

                if ($this->lineHasMoney($trimmed)) {
                    $current['amount_line'] = $trimmed;
                    $current['marker'] = $this->extractTransactionMarker($trimmed);
                }

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($this->containsAmountPair($trimmed) || $this->isMostlyAmountLine($trimmed)) {
                $current['amount_line'] = trim(($current['amount_line'] ?? '').' '.$trimmed);
                $current['marker'] ??= $this->extractTransactionMarker($trimmed);

                continue;
            }

            $current['description'] = $this->appendDescription($current['description'], $trimmed);
        }

        if ($current !== null) {
            $built = $this->finalize($current);

            if ($built !== null) {
                $rows[] = $built;
                $rowPages[] = $currentPage;
            }
        }

        $rows = $this->classifyDebitCredit($rows);

        $headers = ['Date', 'Description', 'Debit', 'Credit', 'Balance'];
        $columns = [];

        foreach ($headers as $index => $header) {
            $samples = [];

            foreach ($rows as $row) {
                $value = trim((string) ($row[$index] ?? ''));

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
                headerText: $header,
                xStart: $index * 20,
                xEnd: ($index + 1) * 20 - 1,
                sampleValues: $samples,
            );
        }

        return new RawTable(
            layoutFingerprint: (new LayoutFingerprint)->make($headers, count($headers), $bankName),
            headerCells: $headers,
            columns: $columns,
            rows: $rows,
            pageOf: $rowPages,
            sourceFile: $sourceFile,
            bankName: $bankName,
            rawPreamble: $rawPreamble,
        );
    }

    /**
     * @param  list<string>  $headerCells
     */
    public function shouldUse(int $slicedRowCount, int $dateLikeLineCount, array $headerCells = []): bool
    {
        if ($dateLikeLineCount < 3) {
            return false;
        }

        if ($this->headersLookBroken($headerCells) || count($headerCells) <= 2) {
            return true;
        }

        return $slicedRowCount < max(3, (int) floor($dateLikeLineCount * 0.5));
    }

    /**
     * @param  list<string>  $lines
     */
    public function countDateLikeLines(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            if ($this->lineContainsDate($this->unglueTokens($line))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $headerCells
     */
    public function headersLookBroken(array $headerCells): bool
    {
        // Only flag glue inside a single cell (Papier). Do not implode cells —
        // "Txn Date" + "Particulars" would false-positive as DateParticulars.
        foreach ($headerCells as $cell) {
            if (preg_match('/(Description|Particulars|Narration)(Debit|Credit|Balance)|(Debit)(Credit|Balance)|(Credit)(Balance)|(Date)(Particulars|Value|Description)/i', $cell) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Papier (and some smalot paths) glue tokens: 01/04/202501/04/2025NARRATION.
     */
    public function unglueTokens(string $line): string
    {
        $line = preg_replace(
            '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})(?=\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/',
            '$1 ',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})(?=[A-Za-z])/',
            '$1 ',
            $line,
        ) ?? $line;

        // Named-month dates glued to narration: 09 Sep 2025UPI/...
        $line = preg_replace(
            '/(\d{1,2}[-\s](?:'.self::MONTHS.')[a-z]*[-\s]\d{2,4})(?=[A-Za-z])/i',
            '$1 ',
            $line,
        ) ?? $line;

        $line = preg_replace('/(Description|Particulars|Narration)(Debit|Credit|Balance)/i', '$1 $2', $line) ?? $line;
        $line = preg_replace('/(Withdrawal)\s*\((Dr\.)\)(Deposit)/i', '$1 ($2) $3', $line) ?? $line;
        $line = preg_replace('/(Deposit)\s*\((Cr\.)\)(Balance)/i', '$1 ($2) $3', $line) ?? $line;
        $line = preg_replace('/(Debit)(Credit|Balance)/i', '$1 $2', $line) ?? $line;
        $line = preg_replace('/(Credit)(Balance)/i', '$1 $2', $line) ?? $line;
        $line = preg_replace('/(Transaction)(Date)/i', '$1 $2', $line) ?? $line;
        $line = preg_replace('/(Value)(Date)/i', '$1 $2', $line) ?? $line;

        return $line;
    }

    /**
     * @param  array{date: ?string, description: string, amount_line: ?string, marker: ?string}  $current
     * @return list<string>|null
     */
    private function finalize(array $current): ?array
    {
        if ($current['date'] === null) {
            return null;
        }

        $description = $this->normalizeDescription($current['description']);
        $amountLine = $current['amount_line'] ?? '';
        // Marker is taken only from amount lines (set while assembling), never from
        // narration — words like "CREDIT" must not become CR markers.
        $marker = $current['marker'];

        if ($this->isOpeningOrClosing($description) && $amountLine === '') {
            return null;
        }

        // Prefer the amount line alone — concatenating a normalized description can
        // re-glue Indian amounts (e.g. "5,400.00 90,51,…" → "5,400.0090,51,…").
        $amountSource = ($amountLine !== '' && $this->lineHasMoney($amountLine))
            ? $amountLine
            : $amountLine.' '.$description;
        $amountBlob = preg_replace('/\b(?:DR|CR)\.?\b/i', ' ', $amountSource) ?? $amountSource;
        [$amount, $balance] = $this->parseAmounts($amountBlob);

        if ($amount === null && $balance === null) {
            return null;
        }

        $cleanDescription = trim(preg_replace(
            '/(?:'.$this->moneyPattern().')+(?:\d{0,4})?\s*$/',
            '',
            $description,
        ) ?? $description);
        $cleanDescription = trim(preg_replace('/\s*\b(?:DR|CR)\.?\s*$/i', '', $cleanDescription) ?? $cleanDescription);
        $cleanDescription = trim(preg_replace('/(?:^|\s)\d{4,8}$/', '', $cleanDescription) ?? $cleanDescription);

        if ($cleanDescription === '' || preg_match('/^(opening|closing|transaction total)/i', $cleanDescription) === 1) {
            return null;
        }

        $formattedAmount = $amount !== null ? number_format(abs($amount), 2, '.', '') : '';
        $formattedBalance = $balance !== null ? number_format($balance, 2, '.', '') : '';

        // Explicit txn markers (Amount Dr/Cr Balance). Balance-nature markers
        // (Amount Balance DR) are ignored here — classifyDebitCredit uses deltas.
        if ($marker === 'DR') {
            return [$current['date'], $cleanDescription, $formattedAmount, '', $formattedBalance];
        }

        if ($marker === 'CR') {
            return [$current['date'], $cleanDescription, '', $formattedAmount, $formattedBalance];
        }

        return [$current['date'], $cleanDescription, '', $formattedAmount, $formattedBalance];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    private function classifyDebitCredit(array $rows): array
    {
        $previousBalance = null;

        foreach ($rows as $index => $row) {
            $debit = $row[2] !== '' ? (float) $row[2] : null;
            $credit = $row[3] !== '' ? (float) $row[3] : null;
            $balance = $row[4] !== '' ? (float) $row[4] : null;

            if ($debit !== null && $credit === null) {
                if ($balance !== null) {
                    $previousBalance = $balance;
                }

                continue;
            }

            $amount = $credit;

            if ($amount === null) {
                if ($balance !== null) {
                    $previousBalance = $balance;
                }

                continue;
            }

            $isCredit = true;

            if ($previousBalance !== null && $balance !== null) {
                $expectedCredit = round($previousBalance + $amount, 2);
                $expectedDebit = round($previousBalance - $amount, 2);

                if (abs($expectedDebit - $balance) <= 0.01) {
                    $isCredit = false;
                } elseif (abs($expectedCredit - $balance) <= 0.01) {
                    $isCredit = true;
                } elseif ($balance < $previousBalance) {
                    $isCredit = false;
                }
            }

            if ($isCredit) {
                $rows[$index][2] = '';
                $rows[$index][3] = number_format($amount, 2, '.', '');
            } else {
                $rows[$index][2] = number_format($amount, 2, '.', '');
                $rows[$index][3] = '';
            }

            if ($balance !== null) {
                $previousBalance = $balance;
            }
        }

        return $rows;
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function parseAmounts(string $text): array
    {
        $balance = null;
        $working = $text;

        if (preg_match_all('/(\d+)\.(\d{2})(\d{2,4})\b/', $working, $glued, PREG_SET_ORDER) > 0) {
            $last = $glued[array_key_last($glued)];
            $balance = (float) ($last[1].'.'.$last[2]);
            $working = preg_replace('/'.preg_quote($last[0], '/').'/', ' ', $working, 1) ?? $working;
        }

        if (preg_match_all('/'.$this->moneyPattern().'/', $working, $matches) === 0) {
            return [null, $balance];
        }

        $values = array_map(
            static fn (string $value): float => (float) str_replace(',', '', $value),
            $matches[0],
        );

        if ($balance === null && count($values) >= 2) {
            return [$values[count($values) - 2], $values[count($values) - 1]];
        }

        if ($balance === null && count($values) === 1) {
            return [$values[0], null];
        }

        return [$values[array_key_last($values)], $balance];
    }

    private function moneyPattern(): string
    {
        return '(?:\d{1,3}(?:,\d{2,3})+\.\d{2}|\d+\.\d{2})';
    }

    private function datePattern(): string
    {
        return '(?:\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}|\d{1,2}[-\s](?:'.self::MONTHS.')[a-z]*[-\s]\d{2,4})';
    }

    /**
     * Kotak (and similar) prefix serial numbers: "1 09 Sep 2025…" or "# 09 Sep…".
     */
    private function stripRowPrefix(string $line): string
    {
        return ltrim(preg_replace('/^(?:#\s*)?(?:\d{1,4}\s+)(?=\d{1,2}[-\/\s])/', '', $line) ?? $line);
    }

    private function isStatementPeriod(string $line): bool
    {
        $line = $this->stripRowPrefix($line);

        return preg_match('/^'.$this->datePattern().'\s*[-–]\s*'.$this->datePattern().'/i', $line) === 1;
    }

    private function isDateLed(string $line): bool
    {
        $line = $this->stripRowPrefix($line);

        // Allow glued follow-on date or letter (Papier): 01/04/202501/04/2025FOO
        return preg_match('/^'.$this->datePattern().'(?=\d{1,2}[-\/]|[A-Za-z]|\b)/i', $line) === 1;
    }

    private function lineContainsDate(string $line): bool
    {
        return preg_match('/(?:^|[^0-9])'.$this->datePattern().'(?=\d{1,2}[-\/]|[A-Za-z]|\b)/i', $line) === 1;
    }

    private function extractDate(string $line): ?string
    {
        $line = $this->stripRowPrefix($line);

        if (preg_match('/^('.$this->datePattern().')/i', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function stripLeadingDates(string $line): string
    {
        $rest = $this->stripRowPrefix($line);

        for ($i = 0; $i < 2; $i++) {
            $next = preg_replace('/^'.$this->datePattern().'/i', '', $rest, 1);

            if ($next === null || $next === $rest) {
                break;
            }

            $rest = ltrim($next);
        }

        return trim($rest);
    }

    private function lineHasMoney(string $line): bool
    {
        return preg_match('/'.$this->moneyPattern().'/', $line) === 1;
    }

    private function containsAmountPair(string $line): bool
    {
        return $this->lineHasMoney($line) && ! $this->isDateLed($line);
    }

    private function isMostlyAmountLine(string $line): bool
    {
        $compact = preg_replace('/[\d,.\s\t]/', '', $line) ?? '';

        return (strlen($compact) <= 2 || preg_match('/^(DR|CR)\.?$/i', $compact) === 1)
            && $this->lineHasMoney($line);
    }

    /**
     * Transaction-direction marker only (e.g. "450.00 Dr 9,550.00").
     * Trailing DR/CR after two amounts is balance nature (CBI) — ignore.
     */
    private function extractTransactionMarker(string $text): ?string
    {
        $text = trim($text);

        // Amount + Balance + DR/CR ⇒ balance nature, not txn direction.
        if (preg_match('/'.$this->moneyPattern().'\s+'.$this->moneyPattern().'\s*\b(?:DR|CR)\.?\b/i', $text) === 1) {
            return null;
        }

        // Amount + Dr/Cr (+ optional Balance)
        if (preg_match('/'.$this->moneyPattern().'\s+\b(DR|CR)\.?\b(?:\s+'.$this->moneyPattern().')?/i', $text, $matches) === 1) {
            return strtoupper(rtrim($matches[1], '.'));
        }

        return null;
    }

    private function appendDescription(string $existing, string $addition): string
    {
        if ($existing === '') {
            return $addition;
        }

        if (preg_match('/[0-9\/\-]$/', $existing) === 1 && preg_match('/^[0-9A-Za-z]/', $addition) === 1) {
            return $existing.$addition;
        }

        return trim($existing.' '.$addition);
    }

    private function normalizeDescription(string $description): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        $description = preg_replace('/(\d{1,2}-)\s+(\d{1,2}-\d{2,4})/', '$1$2', $description) ?? $description;

        // Rejoin mid-token wraps (account numbers), but never across money amounts.
        if (! $this->lineHasMoney($description)) {
            $description = preg_replace('/(\d)\s+(\d)/', '$1$2', $description) ?? $description;
        }

        return $description;
    }

    private function isHardStop(string $line): bool
    {
        $normalized = strtolower($line);

        return str_contains($normalized, 'transaction total')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'unless the constituent')
            || str_contains($normalized, 'end of statement')
            || str_contains($normalized, 'legends')
            || str_contains($normalized, 'registered office')
            || $normalized === 'statement of account'
            || preg_match('/^page\s+\d+\s+of\s+\d+/', $normalized) === 1
            || str_contains($normalized, 'cleared balance')
            || $this->isLegendDetail($line);
    }

    private function isNoise(string $line): bool
    {
        $normalized = strtolower($line);

        return str_contains($normalized, 'legends')
            || str_contains($normalized, 'system generated')
            || str_contains($normalized, 'unless the constituent')
            || str_contains($normalized, 'registered office')
            || str_contains($normalized, 'www.axis')
            || str_contains($normalized, 'www.kotak')
            || str_contains($normalized, 'kotak mahindra')
            || str_contains($normalized, 'iconn-')
            || str_contains($normalized, 'transaction total')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'cleared balance')
            || str_contains($normalized, 'drawing power')
            || str_contains($normalized, 'page ')
            || str_contains($normalized, 'customer id')
            || str_contains($normalized, 'account no')
            || str_contains($normalized, 'statement period')
            || str_contains($normalized, 'statement of account')
            || str_contains($normalized, 'account statement')
            || str_contains($normalized, 'statement generated')
            || str_contains($normalized, 'savings account transactions')
            || preg_match('/^#\s*date\b/i', $normalized) === 1
            || str_contains($normalized, 'account branch')
            || str_contains($normalized, 'branch address')
            || str_contains($normalized, 'branch code')
            || str_contains($normalized, 'account status')
            || str_contains($normalized, 'account type')
            || str_contains($normalized, 'account opening')
            || str_contains($normalized, 'communication')
            || str_contains($normalized, 'email id')
            || str_contains($normalized, 'phone no')
            || str_contains($normalized, 'nomination')
            || str_contains($normalized, 'ckyc')
            || str_contains($normalized, 'central bank of india')
            || preg_match('/^(tran date|transaction date|value date|particulars|debit|credit|balance|che|txn)/', $normalized) === 1
            || $this->isLegendDetail($line);
    }

    private function isLegendDetail(string $line): bool
    {
        $normalized = strtolower($line);

        return preg_match('/^(vmt-?icon|iconn|autosweep|rev\s*sweep|sweep\s*trf)\b/i', $line) === 1
            || str_contains($normalized, 'visa money transfer')
            || str_contains($normalized, 'linked fixed deposit')
            || str_contains($normalized, 'money transfer through');
    }

    private function isOpeningOrClosing(string $description): bool
    {
        return preg_match('/opening balance|closing balance|transaction total/i', $description) === 1;
    }
}
