<?php

namespace App\Data;

use Carbon\CarbonImmutable;

readonly class ParsedStatement
{
    /**
     * @param  list<Transaction>  $transactions
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $transactions,
        public array $warnings = [],
        public ?string $bank = null,
        public ?string $accountNumberMasked = null,
        public ?CarbonImmutable $periodFrom = null,
        public ?CarbonImmutable $periodTo = null,
        public ?float $openingBalance = null,
        public ?float $closingBalance = null,
        public string $sourceFile = '',
        public float $reconciliationMatchPercent = 100.0,
    ) {}
}
