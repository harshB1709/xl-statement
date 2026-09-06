<?php

use App\Exceptions\PdfExtractionFailed;
use App\Exceptions\PdfPasswordRequired;
use App\Services\Pdf\ExtractedText;
use App\Services\Pdf\PapierPdfTextExtractor;
use App\Services\Pdf\PopplerTextExtractor;
use App\Services\Pdf\PreferPhpPdfTextExtractor;
use App\Services\Pdf\SmalotPdfTextExtractor;
use Papier\Elements\Text;
use Papier\Encryption\EncryptionAlgorithm;
use Papier\Encryption\StandardSecurityHandler;
use Papier\PdfDocument;

it('prompts for password on locked pdfs instead of treating them as scanned', function () {
    $path = storage_path('framework/testing/prefer-php-locked.pdf');
    @unlink($path);

    $doc = PdfDocument::create();
    $font = $doc->addFont('Helvetica');
    $page = $doc->addPage();
    $page->add(
        Text::write('14-05-2025 Initial Funding 25000.00 25000.00')
            ->at(72, 750)
            ->font($font, 12),
    );
    $doc->encrypt('test-secret', 'owner-secret', StandardSecurityHandler::PERM_ALL, EncryptionAlgorithm::Aes_256);
    $doc->save($path);

    $extractor = app(PreferPhpPdfTextExtractor::class);

    expect(fn () => $extractor->extract($path))
        ->toThrow(PdfPasswordRequired::class);

    $extracted = $extractor->extract($path, 'test-secret');

    expect($extracted->fullText())->toContain('Initial Funding');
});

it('uses poppler first for unlocked pdfs when the binary is available', function () {
    $path = storage_path('framework/testing/prefer-php-unlocked.txt.pdf');
    $popplerResult = new ExtractedText(['from-poppler'], $path);

    $poppler = Mockery::mock(PopplerTextExtractor::class);
    $poppler->shouldReceive('isAvailable')->andReturn(true);
    $poppler->shouldReceive('extract')->once()->with($path, null)->andReturn($popplerResult);

    $smalot = Mockery::mock(SmalotPdfTextExtractor::class);
    $smalot->shouldNotReceive('extract');

    $papier = Mockery::mock(PapierPdfTextExtractor::class);
    $papier->shouldNotReceive('extract');

    $extractor = new PreferPhpPdfTextExtractor($smalot, $papier, $poppler);

    expect($extractor->extract($path)->fullText())->toBe('from-poppler');
});

it('falls back to smalot when poppler is unavailable', function () {
    $path = storage_path('framework/testing/prefer-php-fallback.pdf');
    $smalotResult = new ExtractedText(['from-smalot'], $path);

    $poppler = Mockery::mock(PopplerTextExtractor::class);
    $poppler->shouldReceive('isAvailable')->andReturn(false);
    $poppler->shouldNotReceive('extract');

    $smalot = Mockery::mock(SmalotPdfTextExtractor::class);
    $smalot->shouldReceive('extract')->once()->with($path, null)->andReturn($smalotResult);

    $papier = Mockery::mock(PapierPdfTextExtractor::class);
    $papier->shouldNotReceive('extract');

    $extractor = new PreferPhpPdfTextExtractor($smalot, $papier, $poppler);

    expect($extractor->extract($path)->fullText())->toBe('from-smalot');
});

it('falls back to smalot when poppler fails on an unlocked pdf', function () {
    $path = storage_path('framework/testing/prefer-php-poppler-fail.pdf');
    $smalotResult = new ExtractedText(['from-smalot'], $path);

    $poppler = Mockery::mock(PopplerTextExtractor::class);
    $poppler->shouldReceive('isAvailable')->andReturn(true);
    $poppler->shouldReceive('extract')
        ->once()
        ->with($path, null)
        ->andThrow(new PdfExtractionFailed('boom', $path));

    $smalot = Mockery::mock(SmalotPdfTextExtractor::class);
    $smalot->shouldReceive('extract')->once()->with($path, null)->andReturn($smalotResult);

    $papier = Mockery::mock(PapierPdfTextExtractor::class);
    $papier->shouldNotReceive('extract');

    $extractor = new PreferPhpPdfTextExtractor($smalot, $papier, $poppler);

    expect($extractor->extract($path)->fullText())->toBe('from-smalot');
});

it('uses poppler first for password pdfs when the binary is available', function () {
    $path = storage_path('framework/testing/prefer-php-password.pdf');
    $popplerResult = new ExtractedText(['unlocked-by-poppler'], $path);

    $poppler = Mockery::mock(PopplerTextExtractor::class);
    $poppler->shouldReceive('isAvailable')->andReturn(true);
    $poppler->shouldReceive('extract')->once()->with($path, 'secret')->andReturn($popplerResult);

    $smalot = Mockery::mock(SmalotPdfTextExtractor::class);
    $smalot->shouldNotReceive('extract');

    $papier = Mockery::mock(PapierPdfTextExtractor::class);
    $papier->shouldNotReceive('extract');

    $extractor = new PreferPhpPdfTextExtractor($smalot, $papier, $poppler);

    expect($extractor->extract($path, 'secret')->fullText())->toBe('unlocked-by-poppler');
});
