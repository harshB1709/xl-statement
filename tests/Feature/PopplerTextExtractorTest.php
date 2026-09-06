<?php

use App\Services\Pdf\PopplerTextExtractor;
use Illuminate\Support\Facades\Process;

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
    file_put_contents($path, "#!/bin/sh\necho ok\n");
    chmod($path, 0755);

    $extractor = new PopplerTextExtractor($path);

    expect($extractor->resolveBinary())->toBe($path)
        ->and($extractor->isAvailable())->toBeTrue();
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
