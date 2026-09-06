<?php

namespace App\Data;

readonly class RawTable
{
    /**
     * @param  list<string>  $headerCells
     * @param  list<RawColumn>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<int>  $pageOf
     */
    public function __construct(
        public string $layoutFingerprint,
        public array $headerCells,
        public array $columns,
        public array $rows,
        public array $pageOf,
        public string $sourceFile,
        public ?string $bankName = null,
        public string $rawPreamble = '',
        public ?string $displayName = null,
    ) {}

    public function withDisplayName(string $displayName): self
    {
        return new self(
            layoutFingerprint: $this->layoutFingerprint,
            headerCells: $this->headerCells,
            columns: $this->columns,
            rows: $this->rows,
            pageOf: $this->pageOf,
            sourceFile: $this->sourceFile,
            bankName: $this->bankName,
            rawPreamble: $this->rawPreamble,
            displayName: $displayName,
        );
    }

    public function label(): string
    {
        return $this->displayName ?? basename($this->sourceFile);
    }
}
