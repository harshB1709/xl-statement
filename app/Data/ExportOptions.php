<?php

namespace App\Data;

readonly class ExportOptions
{
    /**
     * @param  list<string>|null  $columns  Column keys to include (null = defaults).
     *                                      Keys: date, value_date, description, reference,
     *                                      debit, credit, balance, bank, source_file, page
     */
    public function __construct(
        public string $outputPath,
        public bool $indianFormat = false,
        public bool $mergeIntoOneSheet = true,
        public bool $includeSummary = true,
        public bool $sortByDate = true,
        public bool $includeSourceColumns = true,
        public string $excelDateFormat = 'dd-mm-yyyy',
        public ?array $columns = null,
    ) {}
}
