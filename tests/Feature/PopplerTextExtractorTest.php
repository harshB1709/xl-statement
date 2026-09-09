<?php

use App\Services\Pdf\PopplerTextExtractor;
use Illuminate\Support\Facades\Process;

function fakePdftotextBinary(string $path): void
{
    @mkdir(dirname($path), 0777, true);
    // Must be >= 1KB so PopplerTextExtractor rejects Git LFS pointer stubs.
    file_put_contents($path, str_repeat('A', 2048));
    chmod($path, 0755);
}

it('resolves absolute homebrew paths when which cannot see pdftotext', function () {
    config(['statements.pdftotext_path' => null]);

    Process::fake([
        'which pdftotext' => Process::result(output: '', exitCode: 1),
    ]);

    $extractor = new PopplerTextExtractor;
    $binary = $extractor->resolveBinary();

    expect($binary)->toBeReadableFile()
        ->and($binary)->toContain('pdftotext')
        ->and($extractor->isAvailable())->toBeTrue();
})->skip(PHP_OS_FAMILY !== 'Darwin' || ! is_file('/opt/homebrew/bin/pdftotext'), 'Homebrew pdftotext not installed');

it('prefers an explicit constructor binary path', function () {
    $path = storage_path('framework/testing/fake-pdftotext');
    fakePdftotextBinary($path);

    $extractor = new PopplerTextExtractor($path);

    expect($extractor->resolveBinary())->toBe($path)
        ->and($extractor->isAvailable())->toBeTrue();
});

it('resolves the NativePHP packaged extras path before base_path extras', function () {
    config(['statements.pdftotext_path' => null]);

    $platform = PHP_OS_FAMILY === 'Windows' ? 'win' : (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux');
    $binaryName = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
    $extrasRoot = storage_path('framework/testing/nativephp-extras');
    $binary = $extrasRoot.DIRECTORY_SEPARATOR.$platform.DIRECTORY_SEPARATOR.$binaryName;

    fakePdftotextBinary($binary);

    putenv('NATIVEPHP_EXTRAS_PATH='.$extrasRoot);
    $_ENV['NATIVEPHP_EXTRAS_PATH'] = $extrasRoot;
    $_SERVER['NATIVEPHP_EXTRAS_PATH'] = $extrasRoot;

    Process::fake([
        'which pdftotext' => Process::result(output: '', exitCode: 1),
        'where.exe pdftotext' => Process::result(output: '', exitCode: 1),
    ]);

    try {
        $extractor = new PopplerTextExtractor;

        expect($extractor->candidateBinaryPaths()[0])->toBe($binary)
            ->and($extractor->resolveBinary())->toBe($binary)
            ->and($extractor->isAvailable())->toBeTrue();
    } finally {
        putenv('NATIVEPHP_EXTRAS_PATH');
        unset($_ENV['NATIVEPHP_EXTRAS_PATH'], $_SERVER['NATIVEPHP_EXTRAS_PATH']);
    }
});

it('finds extras under base_path when env is unset', function () {
    config(['statements.pdftotext_path' => null]);

    $platform = PHP_OS_FAMILY === 'Windows' ? 'win' : (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux');
    $binaryName = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
    $binary = base_path('extras'.DIRECTORY_SEPARATOR.$platform.DIRECTORY_SEPARATOR.$binaryName);

    // Don't clobber real Windows Poppler binaries checked into extras/win.
    if (is_file($binary) && filesize($binary) > 10_000) {
        test()->markTestSkipped('Real extras binary already present');
    }

    fakePdftotextBinary($binary);

    putenv('NATIVEPHP_EXTRAS_PATH');
    unset($_ENV['NATIVEPHP_EXTRAS_PATH'], $_SERVER['NATIVEPHP_EXTRAS_PATH']);

    Process::fake([
        'which pdftotext' => Process::result(output: '', exitCode: 1),
        'where.exe pdftotext' => Process::result(output: '', exitCode: 1),
    ]);

    try {
        $extractor = new PopplerTextExtractor;

        expect($extractor->resolveBinary())->toBe($binary);
    } finally {
        @unlink($binary);
    }
});

it('rejects git lfs pointer stubs as unusable binaries', function () {
    $path = storage_path('framework/testing/lfs-pointer-pdftotext');
    file_put_contents($path, "version https://git-lfs.github.com/spec/v1\noid sha256:abc\nsize 1\n");
    chmod($path, 0755);

    $extractor = new PopplerTextExtractor($path);

    expect($extractor->isAvailable())->toBeFalse();
});

it('tags extracted text as poppler when run against a real pdf', function () {
    $path = base_path('tests/Fixtures/real/kotak-2.pdf');

    if (! is_file($path)) {
        test()->markTestSkipped('Add kotak-2.pdf under tests/Fixtures/real/');
    }

    $extractor = app(PopplerTextExtractor::class);

    if (! $extractor->isAvailable()) {
        test()->markTestSkipped('pdftotext not available');
    }

    $extracted = $extractor->extract($path);

    expect($extracted->engine)->toBe('poppler')
        ->and($extracted->fullText())->toContain('TRANSACTION');
});
