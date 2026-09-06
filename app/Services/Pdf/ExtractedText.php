<?php

namespace App\Services\Pdf;

readonly class ExtractedText
{
    /**
     * @param  list<string>  $pages
     */
    public function __construct(
        public array $pages,
        public string $sourceFile,
        public string $engine = 'unknown',
    ) {}

    public function fullText(): string
    {
        return implode("\n\f\n", $this->pages);
    }

    public function nonWhitespaceLength(): int
    {
        return strlen(preg_replace('/\s+/', '', $this->fullText()) ?? '');
    }
}
