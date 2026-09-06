<?php

namespace App\Services\Excel;

use App\Data\ExportOptions;
use App\Data\ParsedStatement;
use App\Data\Transaction;
use Carbon\CarbonImmutable;

class StatementWorkbookBuilder
{
    private const EXCEL_EPOCH = '1899-12-30';

    /**
     * @var array<string, array{header: string, width: float, format: ?string}>
     */
    public const COLUMN_DEFINITIONS = [
        'date' => ['header' => 'Date', 'width' => 12.0, 'format' => 'date'],
        'value_date' => ['header' => 'Value Date', 'width' => 12.0, 'format' => 'date'],
        'description' => ['header' => 'Description', 'width' => 48.0, 'format' => null],
        'reference' => ['header' => 'Ref / Cheque No', 'width' => 18.0, 'format' => null],
        'debit' => ['header' => 'Debit', 'width' => 14.0, 'format' => 'amount'],
        'credit' => ['header' => 'Credit', 'width' => 14.0, 'format' => 'amount'],
        'balance' => ['header' => 'Balance', 'width' => 14.0, 'format' => 'amount'],
        'bank' => ['header' => 'Bank', 'width' => 14.0, 'format' => null],
        'source_file' => ['header' => 'Source File', 'width' => 22.0, 'format' => null],
        'page' => ['header' => 'Page', 'width' => 8.0, 'format' => null],
    ];

    /**
     * @param  list<ParsedStatement>  $statements
     */
    public function build(array $statements, ExportOptions $options): XlsxWriter
    {
        $writer = new XlsxWriter;
        $amountFormat = NumberFormats::amount($options->indianFormat);
        $dateFormat = NumberFormats::date($options->excelDateFormat);
        $columnKeys = $this->resolveColumnKeys($options);
        $extraHeaders = $this->collectExtraHeaders($statements);
        if ($options->mergeIntoOneSheet) {
            $transactions = [];

            foreach ($statements as $statement) {
                foreach ($statement->transactions as $transaction) {
                    $transactions[] = $transaction;
                }
            }

            if ($options->sortByDate) {
                usort($transactions, function (Transaction $a, Transaction $b): int {
                    $aDate = $a->date?->timestamp ?? PHP_INT_MAX;
                    $bDate = $b->date?->timestamp ?? PHP_INT_MAX;

                    return $aDate <=> $bDate;
                });
            }

            $writer->addSheet(
                'Transactions',
                $this->headers($columnKeys, $extraHeaders),
                $this->rows($transactions, $columnKeys, $extraHeaders),
                $this->widths($columnKeys, $extraHeaders),
                $this->formats($columnKeys, $extraHeaders, $amountFormat, $dateFormat),
            );
        } else {
            foreach ($statements as $index => $statement) {
                $name = pathinfo($statement->sourceFile, PATHINFO_FILENAME) ?: 'Sheet '.($index + 1);
                $writer->addSheet(
                    $name,
                    $this->headers($columnKeys, $extraHeaders),
                    $this->rows($statement->transactions, $columnKeys, $extraHeaders),
                    $this->widths($columnKeys, $extraHeaders),
                    $this->formats($columnKeys, $extraHeaders, $amountFormat, $dateFormat),
                );
            }
        }

        if ($options->includeSummary) {
            $summaryRows = [];

            foreach ($statements as $statement) {
                $totalDebit = array_sum(array_map(static fn (Transaction $t): float => $t->debit ?? 0.0, $statement->transactions));
                $totalCredit = array_sum(array_map(static fn (Transaction $t): float => $t->credit ?? 0.0, $statement->transactions));

                $summaryRows[] = [
                    basename($statement->sourceFile),
                    $statement->bank,
                    $statement->accountNumberMasked,
                    $this->excelDate($statement->periodFrom),
                    $this->excelDate($statement->periodTo),
                    $statement->openingBalance,
                    $totalDebit,
                    $totalCredit,
                    $statement->closingBalance,
                    count($statement->transactions),
                    count($statement->warnings),
                    $statement->reconciliationMatchPercent.'%',
                ];
            }

            $writer->addSheet(
                'Summary',
                ['File', 'Bank', 'Account', 'Period From', 'Period To', 'Opening Balance', 'Total Debit', 'Total Credit', 'Closing Balance', 'Transactions', 'Warnings', 'Reconciles'],
                $summaryRows,
                [28, 18, 14, 14, 14, 16, 14, 14, 16, 12, 10, 12],
                [
                    3 => $dateFormat,
                    4 => $dateFormat,
                    5 => $amountFormat,
                    6 => $amountFormat,
                    7 => $amountFormat,
                    8 => $amountFormat,
                ],
            );
        }

        $warningRows = [];

        foreach ($statements as $statement) {
            foreach ($statement->warnings as $warning) {
                $warningRows[] = [basename($statement->sourceFile), $warning];
            }
        }

        if ($warningRows !== []) {
            $writer->addSheet('Warnings', ['File', 'Warning'], $warningRows, [28, 80]);
        }

        return $writer;
    }

    /**
     * @return list<string>
     */
    public function resolveColumnKeys(ExportOptions $options): array
    {
        if ($options->columns !== null) {
            $allowed = array_keys(self::COLUMN_DEFINITIONS);

            return array_values(array_filter(
                $options->columns,
                static fn (string $key): bool => in_array($key, $allowed, true),
            ));
        }

        $keys = ['date', 'value_date', 'description', 'reference', 'debit', 'credit', 'balance', 'bank'];

        if ($options->includeSourceColumns) {
            $keys[] = 'source_file';
            $keys[] = 'page';
        }

        return $keys;
    }

    /**
     * @param  list<ParsedStatement>  $statements
     * @return list<string>
     */
    private function collectExtraHeaders(array $statements): array
    {
        $headers = [];

        foreach ($statements as $statement) {
            foreach ($statement->transactions as $transaction) {
                foreach (array_keys($transaction->extras) as $header) {
                    $headers[$header] = true;
                }
            }
        }

        return array_keys($headers);
    }

    /**
     * @param  list<string>  $columnKeys
     * @param  list<string>  $extraHeaders
     * @return list<string>
     */
    private function headers(array $columnKeys, array $extraHeaders): array
    {
        $headers = [];

        foreach ($columnKeys as $key) {
            $headers[] = self::COLUMN_DEFINITIONS[$key]['header'];
        }

        return array_merge($headers, $extraHeaders);
    }

    /**
     * @param  list<Transaction>  $transactions
     * @param  list<string>  $columnKeys
     * @param  list<string>  $extraHeaders
     * @return list<list<int|float|string|null>>
     */
    private function rows(array $transactions, array $columnKeys, array $extraHeaders): array
    {
        $rows = [];

        foreach ($transactions as $transaction) {
            $row = [];

            foreach ($columnKeys as $key) {
                $row[] = match ($key) {
                    'date' => $this->excelDate($transaction->date),
                    'value_date' => $this->excelDate($transaction->valueDate),
                    'description' => $transaction->description,
                    'reference' => $transaction->reference,
                    'debit' => $transaction->debit,
                    'credit' => $transaction->credit,
                    'balance' => $transaction->balance,
                    'bank' => $transaction->bank,
                    'source_file' => $transaction->sourceFile,
                    'page' => $transaction->page,
                    default => null,
                };
            }

            foreach ($extraHeaders as $header) {
                $row[] = $transaction->extras[$header] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $columnKeys
     * @param  list<string>  $extraHeaders
     * @return list<float>
     */
    private function widths(array $columnKeys, array $extraHeaders): array
    {
        $widths = [];

        foreach ($columnKeys as $key) {
            $widths[] = self::COLUMN_DEFINITIONS[$key]['width'];
        }

        foreach ($extraHeaders as $extraHeader) {
            $widths[] = 16.0;
        }

        return $widths;
    }

    /**
     * @param  list<string>  $columnKeys
     * @param  list<string>  $extraHeaders
     * @return array<int, string>
     */
    private function formats(array $columnKeys, array $extraHeaders, string $amountFormat, string $dateFormat): array
    {
        $formats = [];

        foreach ($columnKeys as $index => $key) {
            $kind = self::COLUMN_DEFINITIONS[$key]['format'];

            if ($kind === 'date') {
                $formats[$index] = $dateFormat;
            } elseif ($kind === 'amount') {
                $formats[$index] = $amountFormat;
            }
        }

        return $formats;
    }

    private function excelDate(?CarbonImmutable $date): ?float
    {
        if ($date === null) {
            return null;
        }

        $epoch = CarbonImmutable::parse(self::EXCEL_EPOCH)->startOfDay();

        return (float) $epoch->diffInDays($date->startOfDay(), false);
    }
}
