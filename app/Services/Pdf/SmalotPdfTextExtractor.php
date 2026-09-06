<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfExtractionFailed;
use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class SmalotPdfTextExtractor implements PdfTextExtractor
{
    public function extract(string $path, ?string $password = null): ExtractedText
    {
        if (! is_file($path)) {
            throw new PdfExtractionFailed('PDF file not found.', $path);
        }

        if ($password !== null && $password !== '') {
            throw new PdfPasswordRequired($path);
        }

        try {
            $config = new Config;
            $config->setHorizontalOffset(' ');
            // Do not ignore encryption: empty text from encrypted PDFs was
            // misreported as "scanned". Secured files must raise PdfPasswordRequired.

            $pdf = (new Parser([], $config))->parseFile($path);
            $pages = [];

            foreach ($pdf->getPages() as $page) {
                $pages[] = rtrim($page->getText(), "\r\n");
            }

            if ($pages === []) {
                $pages = [rtrim($pdf->getText(), "\r\n")];
            }
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'secured') || str_contains($message, 'encrypt') || str_contains($message, 'password')) {
                throw new PdfPasswordRequired($path);
            }

            throw new PdfExtractionFailed($exception->getMessage(), $path, previous: $exception);
        }

        $extracted = new ExtractedText($pages, $path, 'smalot');

        if ($extracted->nonWhitespaceLength() < 40) {
            throw new PdfHasNoTextLayer($path);
        }

        return $extracted;
    }
}
