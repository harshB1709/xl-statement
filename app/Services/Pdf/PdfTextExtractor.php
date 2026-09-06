<?php

namespace App\Services\Pdf;

interface PdfTextExtractor
{
    public function extract(string $path, ?string $password = null): ExtractedText;
}
