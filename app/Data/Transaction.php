<?php

namespace App\Data;

use Carbon\CarbonImmutable;

readonly class Transaction
{
    /**
     * @param  array<string, string>  $extras
     */
    public function __construct(
        public ?CarbonImmutable $date,
        public ?CarbonImmutable $valueDate,
        public string $description,
        public ?string $reference,
        public ?float $debit,
        public ?float $credit,
        public ?float $balance,
        public array $extras,
        public int $page,
        public string $sourceFile,
        public ?string $bank = null,
        public ?string $rawDate = null,
    ) {}
}
