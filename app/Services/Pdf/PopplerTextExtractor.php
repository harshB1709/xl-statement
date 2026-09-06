<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfExtractionFailed;
use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class PopplerTextExtractor implements PdfTextExtractor
{
    public function __construct(
        private readonly ?string $binaryPath = null,
    ) {}

    public function extract(string $path, ?string $password = null): ExtractedText
    {
        if (! is_file($path)) {
            throw new PdfExtractionFailed('PDF file not found.', $path);
        }

        $binary = $this->resolveBinary();
        $command = [$binary, '-layout', '-enc', 'UTF-8'];

        if ($password !== null && $password !== '') {
            $command[] = '-upw';
            $command[] = $password;
        }

        $command[] = $path;
        $command[] = '-';

        $result = $this->process()->run($command);

        if ($result->failed()) {
            $error = strtolower($result->errorOutput().$result->output());

            if (str_contains($error, 'incorrect password') || str_contains($error, 'password')) {
                throw new PdfPasswordRequired($path);
            }

            throw new PdfExtractionFailed(
                trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : 'Failed to extract text from PDF.',
                $path,
            );
        }

        $output = $result->output();
        $pages = preg_split("/\f/", $output) ?: [$output];
        $pages = array_values(array_map(static fn (string $page): string => rtrim($page, "\r\n"), $pages));

        $extracted = new ExtractedText($pages, $path);

        if ($extracted->nonWhitespaceLength() < 40) {
            throw new PdfHasNoTextLayer($path);
        }

        return $extracted;
    }

    public function isAvailable(): bool
    {
        try {
            $this->resolveBinary();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function resolveBinary(): string
    {
        if ($this->binaryPath !== null) {
            if (! is_file($this->binaryPath)) {
                throw new RuntimeException('pdftotext binary not found at '.$this->binaryPath);
            }

            return $this->binaryPath;
        }

        $configured = config('statements.pdftotext_path');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $platform = PHP_OS_FAMILY === 'Windows' ? 'win' : (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux');
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
        $bundled = base_path('extras/'.$platform.'/'.$binaryName);

        if (is_file($bundled)) {
            return $bundled;
        }

        $which = $this->process()->run(['which', 'pdftotext']);

        if ($which->successful() && trim($which->output()) !== '') {
            return trim($which->output());
        }

        throw new RuntimeException('pdftotext binary not found. Install poppler or place it under extras/.');
    }

    private function process(): PendingProcess
    {
        return Process::timeout(120);
    }
}
