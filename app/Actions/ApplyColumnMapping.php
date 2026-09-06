<?php

namespace App\Actions;

use App\Data\ColumnMapping;
use App\Data\ParsedStatement;
use App\Data\RawTable;
use App\Data\Transaction;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Services\Statements\BalanceReconciler;
use App\Services\Statements\DateNormalizer;
use App\Services\Statements\IndianAmount;
use Carbon\CarbonImmutable;

class ApplyColumnMapping
{
    public function __construct(
        private readonly IndianAmount $indianAmount = new IndianAmount,
        private readonly DateNormalizer $dateNormalizer = new DateNormalizer,
        private readonly BalanceReconciler $balanceReconciler = new BalanceReconciler,
    ) {}

    public function handle(RawTable $table, ColumnMapping $mapping): ParsedStatement
    {
        $transactions = [];
        $warnings = [];

        foreach ($table->rows as $rowIndex => $row) {
            $dateRaw = null;
            $valueDateRaw = null;
            $descriptionParts = [];
            $reference = null;
            $debit = null;
            $credit = null;
            $amount = null;
            $marker = null;
            $balance = null;
            $extras = [];

            foreach ($row as $columnIndex => $cell) {
                $target = $mapping->targetFor($columnIndex);
                $cell = trim((string) $cell);

                switch ($target) {
                    case TargetField::Date:
                        $dateRaw = $cell;
                        break;
                    case TargetField::ValueDate:
                        $valueDateRaw = $cell;
                        break;
                    case TargetField::Description:
                        $descriptionParts[] = $cell;
                        break;
                    case TargetField::AppendToDescription:
                        if ($cell !== '') {
                            $descriptionParts[] = $cell;
                        }
                        break;
                    case TargetField::Reference:
                        $reference = $cell !== '' ? $cell : null;
                        break;
                    case TargetField::Debit:
                        $debit = $this->indianAmount->parse($cell)?->value;
                        break;
                    case TargetField::Credit:
                        $credit = $this->indianAmount->parse($cell)?->value;
                        break;
                    case TargetField::Amount:
                        $parsed = $this->indianAmount->parse($cell);
                        $amount = $parsed?->value;
                        $marker ??= $parsed?->marker;
                        break;
                    case TargetField::DrCrMarker:
                        if ($cell !== '') {
                            $marker = strtoupper(rtrim($cell, '.'));
                        }
                        break;
                    case TargetField::Balance:
                        $balance = $this->indianAmount->parse($cell)?->value;
                        break;
                    case TargetField::KeepAsExtra:
                        $header = $table->columns[$columnIndex]->headerText ?? 'Extra '.$columnIndex;
                        $extras[$header] = $cell;
                        break;
                    case TargetField::Ignore:
                        break;
                }
            }

            if ($mapping->amountStyle === AmountStyle::SingleWithMarker && $amount !== null) {
                if (in_array(strtoupper((string) $marker), ['DR', 'D'], true)) {
                    $debit = abs($amount);
                    $credit = null;
                } else {
                    $credit = abs($amount);
                    $debit = null;
                }
            }

            if ($mapping->amountStyle === AmountStyle::SignedSingle && $amount !== null) {
                if ($amount < 0) {
                    $debit = abs($amount);
                    $credit = null;
                } else {
                    $credit = abs($amount);
                    $debit = null;
                }
            }

            $date = $this->dateNormalizer->parse($dateRaw, $mapping->dateFormat);
            $valueDate = $this->dateNormalizer->parse($valueDateRaw, $mapping->dateFormat);

            if ($dateRaw !== null && $dateRaw !== '' && $date === null) {
                $warnings[] = 'Row '.($rowIndex + 1).': could not parse date "'.$dateRaw.'"';
            }

            if (($dateRaw === null || $dateRaw === '') && $debit === null && $credit === null && $balance === null) {
                continue;
            }

            $label = $table->label();

            $transactions[] = new Transaction(
                date: $date,
                valueDate: $valueDate,
                description: trim(implode(' ', array_filter($descriptionParts))),
                reference: $reference,
                debit: $debit,
                credit: $credit,
                balance: $balance,
                extras: $extras,
                page: $table->pageOf[$rowIndex] ?? 1,
                sourceFile: $label,
                bank: $table->bankName,
                rawDate: $dateRaw,
            );
        }

        $reconciliation = $this->balanceReconciler->reconcile($transactions);

        foreach ($reconciliation['mismatched_indexes'] as $index) {
            $warnings[] = 'Row '.($index + 1).': balance does not reconcile with previous balance ± amount';
        }

        if ($reconciliation['swap_suggested']) {
            $warnings[] = 'Debit and Credit look swapped — consider swapping the mapping.';
        }

        $dates = array_values(array_filter(array_map(
            static fn (Transaction $transaction): ?CarbonImmutable => $transaction->date,
            $transactions,
        )));

        return new ParsedStatement(
            transactions: $transactions,
            warnings: $warnings,
            bank: $table->bankName,
            periodFrom: $dates === [] ? null : min($dates),
            periodTo: $dates === [] ? null : max($dates),
            sourceFile: $table->label(),
            reconciliationMatchPercent: $reconciliation['match_percent'],
        );
    }
}
