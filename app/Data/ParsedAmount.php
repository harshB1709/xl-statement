<?php

namespace App\Data;

readonly class ParsedAmount
{
    public function __construct(
        public float $value,
        public ?string $marker = null,
    ) {}
}
