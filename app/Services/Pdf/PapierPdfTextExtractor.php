<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfExtractionFailed;
use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use Papier\Parser\PdfParser;
use Throwable;

class PapierPdfTextExtractor implements PdfTextExtractor
{
    public function extract(string $path, ?string $password = null): ExtractedText
    {
        if (! is_file($path)) {
            throw new PdfExtractionFailed('PDF file not found.', $path);
        }

        try {
            $parser = PdfParser::fromFile($path);

            if ($password !== null && $password !== '') {
                $parser->setPassword($password);
            }

            $parser->parse();

            $pages = [];
            $pageCount = count($parser->getPages());

            for ($page = 1; $page <= $pageCount; $page++) {
                $pages[] = rtrim($parser->extractTextFromPageNumber($page), "\r\n");
            }

            if ($pages === []) {
                $pages = [rtrim($parser->extractText(), "\r\n")];
            }
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());

            if (
                str_contains($message, 'password')
                || str_contains($message, 'encrypt')
                || str_contains($message, 'secured')
                || str_contains($message, 'authenticat')
            ) {
                throw new PdfPasswordRequired($path);
            }

            throw new PdfExtractionFailed($exception->getMessage(), $path, previous: $exception);
        }

        $extracted = new ExtractedText($pages, $path);

        if ($extracted->nonWhitespaceLength() < 40) {
            throw new PdfHasNoTextLayer($path);
        }

        return $extracted;
    }
}
