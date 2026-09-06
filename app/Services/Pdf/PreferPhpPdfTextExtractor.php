<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use Throwable;

/**
 * Prefer Poppler (`pdftotext -layout`) when a binary is available — best column
 * layout for bank statements. Fall back to pure-PHP extractors (smalot / Papier)
 * so Mac Herd and Windows builds still work without Poppler.
 */
class PreferPhpPdfTextExtractor implements PdfTextExtractor
{
    public function __construct(
        private readonly SmalotPdfTextExtractor $smalot,
        private readonly PapierPdfTextExtractor $papier,
        private readonly PopplerTextExtractor $poppler,
    ) {}

    public function extract(string $path, ?string $password = null): ExtractedText
    {
        if ($password !== null && $password !== '') {
            return $this->extractEncrypted($path, $password);
        }

        if ($this->poppler->isAvailable()) {
            try {
                return $this->poppler->extract($path, $password);
            } catch (PdfPasswordRequired $exception) {
                throw $exception;
            } catch (Throwable) {
                // Fall through to pure-PHP extractors.
            }
        }

        try {
            return $this->smalot->extract($path, $password);
        } catch (PdfPasswordRequired $exception) {
            return $this->extractEncrypted($path, $password, $exception);
        } catch (PdfHasNoTextLayer $exception) {
            // Defensive: some smalot paths can yield empty text on locked PDFs.
            try {
                return $this->papier->extract($path, $password);
            } catch (PdfPasswordRequired $passwordRequired) {
                throw $passwordRequired;
            } catch (Throwable) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($this->poppler->isAvailable()) {
                try {
                    return $this->poppler->extract($path, $password);
                } catch (Throwable) {
                    throw $exception;
                }
            }

            throw $exception;
        }
    }

    private function extractEncrypted(string $path, ?string $password, ?Throwable $previous = null): ExtractedText
    {
        if ($this->poppler->isAvailable()) {
            try {
                return $this->poppler->extract($path, $password);
            } catch (PdfPasswordRequired) {
                // Wrong/missing password — still try Papier in case Poppler mis-detected.
            } catch (Throwable) {
                // Fall through to Papier.
            }
        }

        try {
            return $this->papier->extract($path, $password);
        } catch (PdfPasswordRequired $exception) {
            throw $exception;
        } catch (Throwable) {
            if ($previous instanceof PdfPasswordRequired) {
                throw $previous;
            }

            throw new PdfPasswordRequired($path);
        }
    }
}
