<?php

namespace App\Data;

readonly class RawColumn
{
    /**
     * @param  list<string>  $sampleValues
     */
    public function __construct(
        public int $index,
        public string $headerText,
        public int $xStart,
        public int $xEnd,
        public array $sampleValues = [],
    ) {}
}
