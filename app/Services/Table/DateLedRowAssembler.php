<?php

namespace App\Services\Table;

use App\Data\RawColumn;
use App\Data\RawTable;
use Carbon\CarbonImmutable;

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
    public function assemble(array $pages, string $sourceFile, ?string $bankName = null, string $rawPreamble = '', string $textEngine = 'unknown'): RawTable
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
        $pendingDescription = '';
        $pendingAmountLine = null;
        $pendingMarker = null;
        $openingBalance = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($this->looksLikeTableHeader($trimmed) || $this->looksLikeHeaderContinuation($trimmed)) {
                $pendingDescription = '';
                $pendingAmountLine = null;
                $pendingMarker = null;

                continue;
            }

            $opening = $this->extractOpeningBalance($trimmed);

            if ($opening !== null) {
                $openingBalance ??= $opening;

                continue;
            }

            if ($this->isNoise($trimmed) || $this->isHardStop($trimmed)) {
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
                $rest = $this->stripLeadingTimeAndValueDate($this->stripLeadingDates($trimmed));
                $hasMoney = $this->lineHasMoney($trimmed);

                $current = [
                    'date' => $date,
                    'description' => $this->appendDescription($pendingDescription, $rest),
                    'amount_line' => $hasMoney ? $trimmed : $pendingAmountLine,
                    'marker' => $hasMoney
                        ? $this->extractTransactionMarker($trimmed)
                        : $pendingMarker,
                ];

                $pendingDescription = '';
                $pendingAmountLine = null;
                $pendingMarker = null;

                continue;
            }

            $isAmountLine = $this->containsAmountPair($trimmed) || $this->isMostlyAmountLine($trimmed);

            if ($isAmountLine) {
                // Summary totals (Opening/Total Debit/Credit/Closing) must not
                // become a parked amount for the first transaction.
                if ($current === null && $this->countMoneyValues($trimmed) >= 3) {
                    $pendingAmountLine = null;
                    $pendingMarker = null;

                    continue;
                }

                if ($current !== null && $this->rowHasMoney($current)) {
                    if ($this->isTrailingTotalsLine($current, $trimmed)) {
                        $built = $this->finalize($current);

                        if ($built !== null) {
                            $rows[] = $built;
                            $rowPages[] = $currentPage;
                        }

                        $current = null;
                        $pendingDescription = '';
                        $pendingAmountLine = null;
                        $pendingMarker = null;

                        continue;
                    }

                    // Row already has amounts — Canara wraps the *next* txn's
                    // deposit/withdrawal line above its date. Park for the next date.
                    $built = $this->finalize($current);

                    if ($built !== null) {
                        $rows[] = $built;
                        $rowPages[] = $currentPage;
                    }

                    $current = null;
                    $pendingAmountLine = $trimmed;
                    $pendingMarker = $this->extractTransactionMarker($trimmed);
                    $pendingDescription = $this->appendDescription(
                        $pendingDescription,
                        $this->stripMoneyAndMarkers($trimmed),
                    );

                    continue;
                }

                if ($current !== null) {
                    $current['amount_line'] = trim(($current['amount_line'] ?? '').' '.$trimmed);
                    $current['marker'] ??= $this->extractTransactionMarker($trimmed);

                    continue;
                }

                $pendingAmountLine = $trimmed;
                $pendingMarker = $this->extractTransactionMarker($trimmed);
                $pendingDescription = $this->appendDescription(
                    $pendingDescription,
                    $this->stripMoneyAndMarkers($trimmed),
                );

                continue;
            }

            if ($current !== null && ! $this->rowHasMoney($current)) {
                $current['description'] = $this->appendDescription($current['description'], $trimmed);

                continue;
            }

            // Ref/time wraps after amounts belong to the completed row; once a
            // new narration is already parked, keep parking its wrap lines too.
            if ($current !== null
                && $this->rowHasMoney($current)
                && $pendingDescription === ''
                && ! $this->looksLikeNewNarration($trimmed)) {
                $current['description'] = $this->appendDescription($current['description'], $trimmed);

                continue;
            }

            $pendingDescription = $this->appendDescription($pendingDescription, $trimmed);
        }

        if ($current !== null) {
            $built = $this->finalize($current);

            if ($built !== null) {
                $rows[] = $built;
                $rowPages[] = $currentPage;
            }
        }

        $rows = $this->classifyDebitCredit($rows, $openingBalance);

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
            textEngine: $textEngine,
        );
    }

    /**
     * @param  list<string>  $headerCells
     * @param  list<list<string>>  $slicedRows
     */
    public function shouldUse(int $slicedRowCount, int $dateLikeLineCount, array $headerCells = [], array $slicedRows = []): bool
    {
        if ($dateLikeLineCount < 2) {
            return false;
        }

        if ($this->headersLookBroken($headerCells) || count($headerCells) <= 2) {
            return true;
        }

        // Canara-style Deposit/Withdrawal tables wrap narration above the date.
        // Prefer date-led when fixed-width under/over-segments vs date-led lines.
        if ($this->headersLookLikeSeparateDepositWithdrawal($headerCells)
            && ($this->slicedRowsLookChopped($slicedRows)
                || $this->slicedRowsLookMisaligned($slicedRows)
                || $slicedRowCount !== $dateLikeLineCount)) {
            return true;
        }

        // HDFC/Swiggy CC: DATE & TIME + single Amount column (no running balance).
        if ($this->headersLookLikeCreditCardAmount($headerCells)) {
            return true;
        }

        if ($this->slicedRowsLookChopped($slicedRows) || $this->slicedRowsLookMisaligned($slicedRows)) {
            return true;
        }

        return $slicedRowCount < max(3, (int) floor($dateLikeLineCount * 0.5));
    }

    /**
     * @param  list<string>  $headerCells
     */
    public function headersLookLikeSeparateDepositWithdrawal(array $headerCells): bool
    {
        $joined = strtolower(implode(' ', $headerCells));

        return str_contains($joined, 'deposit') && str_contains($joined, 'withdrawal');
    }

    /**
     * Credit-card ledgers use one signed Amount column instead of Debit/Credit/Balance.
     *
     * @param  list<string>  $headerCells
     */
    public function headersLookLikeCreditCardAmount(array $headerCells): bool
    {
        $joined = strtolower(implode(' ', $headerCells));
        $hasAmount = str_contains($joined, 'amount');
        $hasDescription = str_contains($joined, 'description')
            || str_contains($joined, 'particulars')
            || str_contains($joined, 'narration');
        $hasRunningBalance = str_contains($joined, 'balance');
        $hasDebitCredit = str_contains($joined, 'debit') || str_contains($joined, 'credit');
        $hasDateTime = (str_contains($joined, 'date') && str_contains($joined, 'time'))
            || str_contains($joined, 'date & time')
            || str_contains($joined, 'date and time');

        return $hasAmount && $hasDescription && ! $hasRunningBalance && ! $hasDebitCredit
            && ($hasDateTime || str_contains($joined, 'pi'));
    }

    /**
     * Page-to-page Poppler column drift (Airtel/BOI) leaves "-" placeholders and
     * credit+balance mashed into one cell — fixed-width slices are unreliable.
     *
     * @param  list<list<string>>  $rows
     */
    public function slicedRowsLookMisaligned(array $rows): bool
    {
        $checked = 0;
        $bad = 0;

        foreach (array_slice($rows, 0, 50) as $row) {
            foreach ($row as $cell) {
                $cell = trim((string) $cell);

                if ($cell === '') {
                    continue;
                }

                $checked++;

                if (preg_match('/\d+\.\d{2}\s+-/', $cell) === 1 || preg_match('/(^|\s)-\s+\d+\.\d{2}/', $cell) === 1) {
                    $bad++;
                } elseif (preg_match('/\d+\.\d{2}\s+₹/', $cell) === 1) {
                    $bad++;
                } elseif (preg_match('/\d+\.\d{2}.{0,20}\d{1,3}(?:,\d{2,3})+\.\d{2}/', $cell) === 1) {
                    // Two full money amounts jammed into one cell (credit+balance drift).
                    $bad++;
                }
            }
        }

        return $checked >= 8 && $bad >= 3;
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

        // Poppler -layout can leave compound headers split ("Post" | "Date"),
        // which makes the fixed-width slicer invent Frankenstein cells on CBI.
        $normalized = array_map(
            static fn (string $cell): string => strtolower(trim($cell)),
            $headerCells,
        );

        // Tokeniser sometimes leaves a lone "/" from "CHQ / REF NO.".
        if (in_array('/', $normalized, true)) {
            return true;
        }

        // HDFC CC Poppler splits "DATE & TIME" into DATE | & | TIME.
        if (in_array('&', $normalized, true)) {
            return true;
        }

        // Two-line CBI headers leave orphan first words when the second line
        // ("Date" / "Code" / "Number") is not merged into the boundary line.
        if (in_array('value', $normalized, true) && ! in_array('value date', $normalized, true)) {
            return true;
        }

        $splitPairs = [
            ['post', 'date'],
            ['value', 'date'],
            ['txn', 'date'],
            ['transaction', 'date'],
            ['transaction', 'description'],
            ['transaction', 'details'],
            ['branch', 'code'],
            ['cheque', 'no'],
            ['chq', 'no'],
        ];

        for ($index = 0; $index < count($normalized) - 1; $index++) {
            foreach ($splitPairs as [$left, $right]) {
                if ($normalized[$index] === $left && $normalized[$index + 1] === $right) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fixed-width cuts through dates/amounts look like "/04/2025" or ",23,500.00".
     *
     * @param  list<list<string>>  $rows
     */
    public function slicedRowsLookChopped(array $rows): bool
    {
        $checked = 0;
        $chopped = 0;

        foreach (array_slice($rows, 0, 40) as $row) {
            foreach ($row as $cell) {
                $cell = trim((string) $cell);

                if ($cell === '') {
                    continue;
                }

                $checked++;

                if (preg_match('/^\/\d{1,2}\//', $cell) === 1) {
                    $chopped++;
                } elseif (preg_match('/^,\d/', $cell) === 1) {
                    $chopped++;
                } elseif (preg_match('/^\d{1,2}\/\d{2}$/', $cell) === 1) {
                    $chopped++;
                } elseif (preg_match('/\d\s+\d{1,2},\d{2},\d{3}\.\d{2}/', $cell) === 1) {
                    // e.g. "9 0,46,057.06" from a cut through 90,46,057.06
                    $chopped++;
                } elseif (preg_match('/^\d{1,3},\d{2}$/', $cell) === 1) {
                    // Canara/Poppler mid-amount cut: "25,00" from 25,000.00
                    $chopped++;
                } elseif (preg_match('/\d{1,3}(?:,\d{2,3})+\.$/', $cell) === 1) {
                    // Trailing decimal cut: "2,372." or "43,072."
                    $chopped++;
                } elseif (preg_match('/^0,\d{3}\.\d{2}$/', $cell) === 1) {
                    // Leading digit dropped: "0,000.00" from 40,000.00
                    $chopped++;
                } elseif (preg_match('/^\.\d{2}$/', $cell) === 1) {
                    // Balance fragment: ".55"
                    $chopped++;
                } elseif (preg_match('/^\d{1,3}(?:,\d{2,3})+$/', $cell) === 1) {
                    // Amount without decimals: "7,536" split from 7,536.55
                    $chopped++;
                } elseif (preg_match('/'.$this->moneyPattern().'\s+\d{2,4}$/', $cell) === 1) {
                    // Money glued to a year/fragment: "13,000.00 023"
                    $chopped++;
                } elseif (preg_match_all('/\d{1,2}[-\/]\d{1,2}/', $cell) >= 3) {
                    // Several day/month fragments stacked in one cell (CC column drift)
                    $chopped++;
                }
            }
        }

        return $checked >= 4 && $chopped >= 2 && ($chopped >= 3 || ($chopped / $checked) >= 0.25);
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

        $dashColumns = $this->parseDashSeparatedAmounts($amountBlob);
        if ($dashColumns !== null) {
            [$debit, $credit, $balance] = $dashColumns;
            $amount = $debit ?? $credit;
            $marker = $debit !== null ? 'DR' : ($credit !== null ? 'CR' : $marker);
        } else {
            [$amount, $balance] = $this->parseAmounts($amountBlob);
            $marker ??= $this->extractSignedAmountMarker($amountSource);
        }

        if ($amount === null && $balance === null) {
            return null;
        }

        $cleanDescription = $this->stripCurrencyMarkers($description);
        // When amounts were captured from the same line, drop leftover money / "-" placeholders.
        if ($amountLine !== '') {
            $cleanDescription = preg_replace('/\s*(?:'.$this->moneyPattern().'|\s-\s|^-\s+|-\s*$)+\s*/', ' ', $cleanDescription) ?? $cleanDescription;
        } else {
            $cleanDescription = preg_replace(
                '/(?:(?:₹|rs\.?|inr|C)\s*)?(?:'.$this->moneyPattern().')+(?:\d{0,4})?\s*$/iu',
                '',
                $cleanDescription,
            ) ?? $cleanDescription;
        }
        $cleanDescription = trim(preg_replace('/\s+/', ' ', $cleanDescription) ?? $cleanDescription);
        $cleanDescription = trim(preg_replace('/\s*\b(?:DR|CR)\.?\s*$/i', '', $cleanDescription) ?? $cleanDescription);
        $cleanDescription = trim(preg_replace('/(?:^|\s)\d{4,8}$/', '', $cleanDescription) ?? $cleanDescription);
        // Strip leading txn ids that DateLed left in the description blob.
        $cleanDescription = trim(preg_replace('/^(?:[A-Z]{2,}\d[\w]*\s+)/', '', $cleanDescription) ?? $cleanDescription);
        // HDFC CC leftovers: purchase-indicator bullet, currency stub, signed "+".
        $cleanDescription = trim(preg_replace('/\s+[+l]$/i', '', $cleanDescription) ?? $cleanDescription);
        $cleanDescription = trim(preg_replace('/(?:^|\s)C$/i', '', $cleanDescription) ?? $cleanDescription);
        $cleanDescription = trim(preg_replace('/\s+\+$/', '', $cleanDescription) ?? $cleanDescription);

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

        // HDFC CC Amount column: unsigned "C 1,234.00" is a purchase (debit).
        if ($balance === null && $amount !== null && $this->looksLikeCreditCardAmountLine($amountSource)) {
            return [$current['date'], $cleanDescription, $formattedAmount, '', ''];
        }

        return [$current['date'], $cleanDescription, '', $formattedAmount, $formattedBalance];
    }

    /**
     * HDFC CC marks payments/cashback with a leading "+" before the amount.
     */
    private function extractSignedAmountMarker(string $text): ?string
    {
        $money = $this->moneyPattern();

        if (preg_match('/\+\s*(?:C\s*)?'.$money.'/i', $text) === 1) {
            return 'CR';
        }

        return null;
    }

    private function looksLikeCreditCardAmountLine(string $text): bool
    {
        $money = $this->moneyPattern();

        return preg_match('/(?:^|[+\s])C\s*'.$money.'/i', $text) === 1
            || preg_match('/\+\s*'.$money.'/', $text) === 1;
    }

    /**
     * Airtel-style columns use "-" for the empty debit/credit slot:
     * "- 21.00 21.00" (credit) or "145.00 - 876.00" (debit).
     *
     * @return array{0: ?float, 1: ?float, 2: ?float}|null
     */
    private function parseDashSeparatedAmounts(string $text): ?array
    {
        $working = $this->stripCurrencyMarkers($text);
        $money = $this->moneyPattern();

        if (preg_match('/(?:^|[\s])-\s*('.$money.')\s+('.$money.')(?:\D|$)/', $working, $matches) === 1) {
            return [null, (float) str_replace(',', '', $matches[1]), (float) str_replace(',', '', $matches[2])];
        }

        if (preg_match('/('.$money.')\s*-\s*('.$money.')(?:\D|$)/', $working, $matches) === 1) {
            return [(float) str_replace(',', '', $matches[1]), null, (float) str_replace(',', '', $matches[2])];
        }

        return null;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    private function classifyDebitCredit(array $rows, ?float $openingBalance = null): array
    {
        if ($this->looksReverseChronological($rows)) {
            return $this->classifyDebitCreditReverse($rows);
        }

        $previousBalance = $openingBalance;

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
     * Newest-first statements: this row's amount explains the jump to the *next*
     * (older) balance, not the jump from the previous newer row.
     *
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    private function classifyDebitCreditReverse(array $rows): array
    {
        $count = count($rows);

        for ($index = 0; $index < $count; $index++) {
            $debit = $rows[$index][2] !== '' ? (float) $rows[$index][2] : null;
            $credit = $rows[$index][3] !== '' ? (float) $rows[$index][3] : null;
            $balance = $rows[$index][4] !== '' ? (float) $rows[$index][4] : null;
            $amount = $debit ?? $credit;

            if ($amount === null || $balance === null) {
                continue;
            }

            $isCredit = $credit !== null && $debit === null;
            $nextBalance = null;

            for ($next = $index + 1; $next < $count; $next++) {
                if ($rows[$next][4] !== '') {
                    $nextBalance = (float) $rows[$next][4];
                    break;
                }
            }

            if ($nextBalance !== null) {
                $afterCredit = round($balance - $amount, 2);
                $afterDebit = round($balance + $amount, 2);

                if (abs($afterCredit - $nextBalance) <= 0.01) {
                    $isCredit = true;
                } elseif (abs($afterDebit - $nextBalance) <= 0.01) {
                    $isCredit = false;
                } elseif ($nextBalance < $balance) {
                    $isCredit = true;
                } elseif ($nextBalance > $balance) {
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
        }

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function looksReverseChronological(array $rows): bool
    {
        $dates = [];

        foreach ($rows as $row) {
            $raw = trim((string) ($row[0] ?? ''));

            if ($raw === '') {
                continue;
            }

            try {
                $dates[] = CarbonImmutable::parse($raw)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if (count($dates) >= 12) {
                break;
            }
        }

        if (count($dates) < 3) {
            return false;
        }

        $descending = 0;
        $ascending = 0;

        for ($index = 1; $index < count($dates); $index++) {
            $cmp = $dates[$index] <=> $dates[$index - 1];

            if ($cmp < 0) {
                $descending++;
            } elseif ($cmp > 0) {
                $ascending++;
            }
        }

        return $descending > $ascending;
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function parseAmounts(string $text): array
    {
        $balance = null;
        $working = $this->stripCurrencyMarkers($text);

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
        // Negative lookahead skips rate/fee percents like "1.75% on all DCC".
        return '(?:\d{1,3}(?:,\d{2,3})+\.\d{2}|\d+\.\d{2})(?!%)';
    }

    private function stripCurrencyMarkers(string $text): string
    {
        // Poppler often renders ₹ as a lone "C" before amounts on HDFC CC PDFs.
        $text = preg_replace('/(?:₹|(?<![a-z])rs\.?(?![a-z])|(?<![a-z])inr(?![a-z])|(?<![A-Za-z0-9])C(?=\s*\d))/iu', '', $text) ?? $text;

        return preg_replace('/\s{2,}/', ' ', $text) ?? $text;
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

    private function stripLeadingTimeAndValueDate(string $rest): string
    {
        // HDFC CC: "15/06/2026| 00:00" or "14/06/2026 | 00:00"
        $rest = preg_replace('/^\|\s*\d{1,2}:\d{2}(:\d{2})?\s*/', '', $rest) ?? $rest;
        $rest = preg_replace('/^\d{1,2}:\d{2}(:\d{2})?\s+/', '', $rest) ?? $rest;
        $rest = preg_replace('/^EMI\b\s*/i', '', $rest) ?? $rest;
        $rest = preg_replace(
            '/^\d{1,2}[-\s](?:'.self::MONTHS.')[a-z]*[-\s]\d{2,4}\s+/i',
            '',
            $rest,
        ) ?? $rest;

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

    /**
     * @param  array{date: ?string, description: string, amount_line: ?string, marker: ?string}  $current
     */
    private function rowHasMoney(array $current): bool
    {
        $amountLine = $current['amount_line'] ?? '';

        return $amountLine !== '' && $this->lineHasMoney($amountLine);
    }

    private function extractOpeningBalance(string $line): ?float
    {
        if (preg_match('/\bopening balance\b/i', $line) !== 1) {
            return null;
        }

        if (preg_match_all('/'.$this->moneyPattern().'/', $line, $matches) === 0) {
            return null;
        }

        return (float) str_replace(',', '', $matches[0][array_key_last($matches[0])]);
    }

    private function stripMoneyAndMarkers(string $line): string
    {
        $clean = preg_replace('/\s*(?:'.$this->moneyPattern().'|\s-\s|^-\s+|-\s*$)+\s*/', ' ', $line) ?? $line;
        $clean = preg_replace('/\s*\b(?:DR|CR)\.?\b\s*/i', ' ', $clean) ?? $clean;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }

    private function appendDescription(string $existing, string $addition): string
    {
        $addition = trim($addition);

        if ($addition === '' || $addition === '.') {
            return $existing;
        }

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

    /**
     * Totals rows after a complete txn (HDFC "STATEMENT SUMMARY" amount line)
     * must not overwrite the last withdrawal/deposit + balance pair.
     *
     * @param  array{date: ?string, description: string, amount_line: ?string, marker: ?string}  $current
     */
    private function isTrailingTotalsLine(array $current, string $line): bool
    {
        $existing = $current['amount_line'] ?? '';

        if ($existing === '' || ! $this->lineHasMoney($existing)) {
            return false;
        }

        return $this->countMoneyValues($line) >= 3;
    }

    private function countMoneyValues(string $line): int
    {
        if (preg_match_all('/'.$this->moneyPattern().'/', $line, $matches) === 0) {
            return 0;
        }

        return count($matches[0]);
    }

    private function isHardStop(string $line): bool
    {
        $normalized = strtolower($line);

        return str_contains($normalized, 'transaction total')
            || str_contains($normalized, 'closing bal')
            || str_contains($normalized, 'statement summary')
            || str_contains($normalized, 'cash back summary')
            || str_contains($normalized, 'cashback summary')
            || str_contains($normalized, 'gst summary')
            || str_contains($normalized, 'eligible for emi')
            || str_contains($normalized, 'dr count')
            || str_contains($normalized, 'cr count')
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
            || str_contains($normalized, 'statement summary')
            || str_contains($normalized, 'dr count')
            || str_contains($normalized, 'cr count')
            || str_contains($normalized, 'generated on')
            || str_contains($normalized, 'generated by')
            || str_contains($normalized, 'require signature')
            || str_contains($normalized, 'requesting branch')
            || str_contains($normalized, 'bank limited')
            || str_contains($normalized, 'closing bal')
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
            || str_contains($normalized, 'ckyc id')
            || str_contains($normalized, 'airtel payments bank')
            || str_contains($normalized, 'gst number')
            || str_contains($normalized, 'help & support')
            || str_contains($normalized, 'end of statement')
            || str_contains($normalized, 'total credit')
            || str_contains($normalized, 'total debit')
            || str_contains($normalized, 'computer-generated')
            || str_contains($normalized, 'computer generated')
            || str_contains($normalized, 'wecare@')
            || str_contains($normalized, 'deposit insurance')
            || str_contains($normalized, 'found correct by you')
            || str_contains($normalized, 'bank never asks')
            || str_contains($normalized, 'do not share your atm')
            || str_contains($normalized, 'central bank of india')
            || str_contains($normalized, 'domestic transactions')
            || str_contains($normalized, 'international transactions')
            || str_contains($normalized, 'purchase indicator')
            || str_contains($normalized, 'transaction time captured')
            || str_contains($normalized, 'offers on your card')
            || str_contains($normalized, 'benefits on your card')
            || str_contains($normalized, 'hsn code')
            || preg_match('/^(tran date|transaction date|value date|particulars|debit|credit|balance|che|txn)/', $normalized) === 1
            || preg_match('/^date\b.*\b(particulars|narration|description|deposits|withdrawals|balance|amount|time)\b/', $normalized) === 1
            || preg_match('/^statement for\b/', $normalized) === 1
            || preg_match('/^(customer id|name|phone|address|ifsc|branch name|branch code)\b/', $normalized) === 1
            || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $normalized) === 1
            || $this->isLegendDetail($line);
    }

    private function looksLikeTableHeader(string $line): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $line) ?? $line));

        if ($normalized === '') {
            return false;
        }

        $hits = 0;

        foreach ([
            'post date', 'value date', 'txn date', 'transaction date', 'date & time', 'date and time',
            'particulars', 'narration', 'description', 'deposits', 'withdrawals',
            'debit', 'credit', 'balance', 'amount', 'cheque', 'branch',
        ] as $token) {
            if (str_contains($normalized, $token)) {
                $hits++;
            }
        }

        if ($hits >= 3) {
            return true;
        }

        return preg_match('/\bdate\b/', $normalized) === 1 && $hits >= 2;
    }

    private function looksLikeHeaderContinuation(string $line): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $line) ?? $line));

        if ($normalized === '') {
            return false;
        }

        // CBI Poppler second header line: "Date Code Number"
        return preg_match('/^(?:date|value|branch|cheque|code|number)(?:\s+(?:date|value|branch|cheque|code|number))*$/', $normalized) === 1
            && ! $this->lineHasMoney($normalized)
            && ! $this->isDateLed($normalized);
    }

    private function looksLikeNewNarration(string $line): bool
    {
        return preg_match(
            '/\b(?:UPI\/|NEFT|IMPS|RTGS|ACH|ATM|POS|NWD|EAW|ATW|CASH\s|SMS\s|EMI\s|FT\s*-|IB\s+BILLPAY|IB\s+NEFT|IB-IMPS|INET-IMPS|MOB-IMPS|MICRO\s+ATM|CREDIT\s+INTEREST|SBINT|ECS\s|PMSBY|DEBIT\s+CARD|By\s+Clg|CASA\.|SELF\s+-)/i',
            $line,
        ) === 1;
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
