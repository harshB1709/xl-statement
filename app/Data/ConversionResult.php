<?php

namespace App\Data;

readonly class ConversionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $outputPath,
        public int $fileCount,
        public int $transactionCount,
        public array $warnings = [],
    ) {}
}
