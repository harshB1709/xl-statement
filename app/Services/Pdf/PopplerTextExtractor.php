<?php

namespace App\Services\Pdf;

use App\Exceptions\PdfExtractionFailed;
use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

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

        $result = $this->process()
            ->path(dirname($binary))
            ->run($command);

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

        $extracted = new ExtractedText($pages, $path, 'poppler');

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
            if (! $this->isUsableBinary($this->binaryPath)) {
                throw new RuntimeException('pdftotext binary not found at '.$this->binaryPath);
            }

            return $this->binaryPath;
        }

        $configured = config('statements.pdftotext_path');

        if (is_string($configured) && $configured !== '' && $this->isUsableBinary($configured)) {
            return $configured;
        }

        foreach ($this->candidateBinaryPaths() as $candidate) {
            if ($this->isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        $resolved = $this->resolveFromPathLookup();

        if ($resolved !== null) {
            return $resolved;
        }

        throw new RuntimeException('pdftotext binary not found. Install poppler or place it under extras/.');
    }

    /**
     * @return list<string>
     */
    public function candidateBinaryPaths(): array
    {
        $platform = PHP_OS_FAMILY === 'Windows' ? 'win' : (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux');
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
        $relative = $platform.DIRECTORY_SEPARATOR.$binaryName;

        $candidates = [];

        foreach ($this->extrasRootCandidates() as $extrasRoot) {
            $candidates[] = rtrim($extrasRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
        }

        try {
            if (config('filesystems.disks.extras.root')) {
                $candidates[] = Storage::disk('extras')->path($platform.'/'.$binaryName);
            }
        } catch (Throwable) {
            // Disk may be unconfigured outside NativePHP.
        }

        $candidates[] = base_path('extras'.DIRECTORY_SEPARATOR.$relative);

        foreach ($this->ancestorExtrasCandidates($relative) as $candidate) {
            $candidates[] = $candidate;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = '/opt/homebrew/bin/pdftotext';
            $candidates[] = '/usr/local/bin/pdftotext';
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $candidates[] = '/usr/bin/pdftotext';
            $candidates[] = '/usr/local/bin/pdftotext';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array{available: bool, binary: ?string, extras_env: ?string, checked: list<array{path: string, usable: bool}>}
     */
    public function diagnose(): array
    {
        $checked = [];

        foreach ($this->candidateBinaryPaths() as $candidate) {
            $checked[] = [
                'path' => $candidate,
                'usable' => $this->isUsableBinary($candidate),
            ];
        }

        $binary = null;

        try {
            $binary = $this->resolveBinary();
        } catch (RuntimeException) {
            $binary = null;
        }

        return [
            'available' => $binary !== null,
            'binary' => $binary,
            'extras_env' => $this->nativephpExtrasPath(),
            'checked' => $checked,
        ];
    }

    /**
     * @return list<string>
     */
    private function extrasRootCandidates(): array
    {
        $roots = [];
        $fromEnv = $this->nativephpExtrasPath();

        if ($fromEnv !== null) {
            $roots[] = $fromEnv;
        }

        return $roots;
    }

    private function nativephpExtrasPath(): ?string
    {
        foreach (['NATIVEPHP_EXTRAS_PATH'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $viaHelper = env('NATIVEPHP_EXTRAS_PATH');

        if (is_string($viaHelper) && $viaHelper !== '') {
            return $viaHelper;
        }

        return null;
    }

    /**
     * Packaged NativePHP keeps extras next to the exe, while Laravel base_path is
     * under resources/build/app — walk ancestors and PHP binary parents.
     *
     * @return list<string>
     */
    private function ancestorExtrasCandidates(string $relative): array
    {
        $candidates = [];
        $anchors = [base_path()];

        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $anchors[] = dirname(PHP_BINARY);
        }

        foreach ($anchors as $anchor) {
            $dir = $anchor;

            for ($i = 0; $i < 6; $i++) {
                $candidates[] = $dir.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.$relative;
                $parent = dirname($dir);

                if ($parent === $dir) {
                    break;
                }

                $dir = $parent;
            }
        }

        return $candidates;
    }

    private function isUsableBinary(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);

        // Real pdftotext is tens of KB+; Git LFS pointer stubs are ~100 bytes.
        if ($size === false || $size < 1024) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 64);
        fclose($handle);

        if (! is_string($head)) {
            return false;
        }

        if (str_starts_with($head, 'version https://git-lfs.github.com/spec/v1')) {
            return false;
        }

        return true;
    }

    private function resolveFromPathLookup(): ?string
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['where.exe', 'pdftotext']
            : ['which', 'pdftotext'];

        $result = $this->process()->run($command);

        if (! $result->successful()) {
            return null;
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($result->output())) ?: [];
        $first = trim((string) ($lines[0] ?? ''));

        if ($first === '' || ! $this->isUsableBinary($first)) {
            return null;
        }

        return $first;
    }

    private function process(): PendingProcess
    {
        return Process::timeout(120);
    }
}
