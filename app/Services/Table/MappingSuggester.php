<?php

namespace App\Services\Table;

use App\Data\ColumnMapping;
use App\Data\RawTable;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Services\Statements\DateNormalizer;
use App\Services\Statements\IndianAmount;

class MappingSuggester
{
    public function __construct(
        private readonly IndianAmount $indianAmount = new IndianAmount,
        private readonly DateNormalizer $dateNormalizer = new DateNormalizer,
    ) {}

    public function suggest(RawTable $table): ColumnMapping
    {
        $targets = [];
        $amountColumns = [];

        foreach ($table->columns as $column) {
            $header = strtolower($column->headerText);
            $target = $this->fromHeader($header);

            if ($target === TargetField::Ignore) {
                $target = $this->fromValues($column->sampleValues);
            }

            $targets[$column->index] = $target;

            if (in_array($target, [TargetField::Debit, TargetField::Credit, TargetField::Amount, TargetField::Balance], true)) {
                $amountColumns[] = $target;
            }
        }

        $amountStyle = $this->inferAmountStyle($targets);
        $dateFormat = $this->inferDateFormat($table, $targets);

        return new ColumnMapping(
            targets: $targets,
            dateFormat: $dateFormat,
            amountStyle: $amountStyle,
        );
    }

    private function fromHeader(string $header): TargetField
    {
        return match (true) {
            str_contains($header, 'value date'), str_contains($header, 'val date') => TargetField::ValueDate,
            str_contains($header, 'date') => TargetField::Date,
            str_contains($header, 'narration'),
            str_contains($header, 'description'),
            str_contains($header, 'particular'),
            str_contains($header, 'remark'),
            str_contains($header, 'detail') => TargetField::Description,
            str_contains($header, 'chq'),
            str_contains($header, 'cheque'),
            str_contains($header, 'ref'),
            str_contains($header, 'utr') => TargetField::Reference,
            str_contains($header, 'withdrawal'),
            str_contains($header, 'debit'),
            preg_match('/\bdr\b/', $header) === 1 => TargetField::Debit,
            str_contains($header, 'deposit'),
            str_contains($header, 'credit'),
            preg_match('/\bcr\b/', $header) === 1 => TargetField::Credit,
            str_contains($header, 'balance') => TargetField::Balance,
            str_contains($header, 'amount') => TargetField::Amount,
            default => TargetField::Ignore,
        };
    }

    /**
     * @param  list<string>  $samples
     */
    private function fromValues(array $samples): TargetField
    {
        if ($samples === []) {
            return TargetField::Ignore;
        }

        $dateHits = 0;
        $amountHits = 0;
        $markerHits = 0;

        foreach ($samples as $sample) {
            if ($this->dateNormalizer->parse($sample, 'd/m/Y') !== null || $this->dateNormalizer->parse($sample, 'd-M-Y') !== null) {
                $dateHits++;
            }

            if ($this->indianAmount->parse($sample) !== null) {
                $amountHits++;
            }

            if (preg_match('/^(cr|dr|c|d)\.?$/i', trim($sample)) === 1) {
                $markerHits++;
            }
        }

        $count = count($samples);

        if ($markerHits / $count >= 0.8) {
            return TargetField::DrCrMarker;
        }

        if ($dateHits / $count >= 0.8) {
            return TargetField::Date;
        }

        if ($amountHits / $count >= 0.8) {
            return TargetField::Amount;
        }

        return TargetField::Ignore;
    }

    /**
     * @param  array<int, TargetField>  $targets
     */
    private function inferAmountStyle(array $targets): AmountStyle
    {
        $hasDebit = in_array(TargetField::Debit, $targets, true);
        $hasCredit = in_array(TargetField::Credit, $targets, true);
        $hasAmount = in_array(TargetField::Amount, $targets, true);
        $hasMarker = in_array(TargetField::DrCrMarker, $targets, true);

        if ($hasDebit && $hasCredit) {
            return AmountStyle::SeparateDrCr;
        }

        if ($hasAmount && $hasMarker) {
            return AmountStyle::SingleWithMarker;
        }

        if ($hasAmount) {
            return AmountStyle::SignedSingle;
        }

        return AmountStyle::SeparateDrCr;
    }

    /**
     * @param  array<int, TargetField>  $targets
     */
    private function inferDateFormat(RawTable $table, array $targets): string
    {
        $dateColumn = null;

        foreach ($targets as $index => $target) {
            if ($target === TargetField::Date) {
                $dateColumn = $index;
                break;
            }
        }

        if ($dateColumn === null) {
            return 'd/m/Y';
        }

        $samples = [];

        foreach ($table->rows as $row) {
            if (($row[$dateColumn] ?? '') !== '') {
                $samples[] = $row[$dateColumn];
            }

            if (count($samples) >= 20) {
                break;
            }
        }

        return $this->dateNormalizer->detectFormat($samples)['format'];
    }
}
