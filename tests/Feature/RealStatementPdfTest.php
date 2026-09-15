<?php

use App\Actions\ApplyColumnMapping;
use App\Actions\ExtractStatementTable;
use App\Services\Table\MappingSuggester;

/**
 * Local-only checks against real PDFs in tests/Fixtures/real/.
 * Skipped automatically when the file is missing (CI-safe).
 */
dataset('real_statement_pdfs', [
    'axis' => ['axis.pdf', 20, 90.0],
    'idfc' => ['idfc.pdf', 20, 90.0],
    'airtel' => ['airtel.pdf', 50, 90.0],
    'boi' => ['boi.pdf', 20, 90.0],
    'hdfc' => ['hdfc.pdf', 80, 90.0],
    // Password in sibling hdfc-cc.password (gitignored with the PDF).
    'hdfc-cc' => ['hdfc-cc.pdf', 30, 100.0],
    // Source PDF has one internal balance jump (25k withdrawal vs prior balance);
    // extractor still returns the 10 posted rows with correct amounts.
    'canara' => ['canara.pdf', 8, 85.0],
    'canara23-24' => ['canara23-24.pdf', 150, 95.0],
]);

it('extracts and reconciles real statement pdfs', function (string $filename, int $minRows, float $minReconcile) {
    $path = base_path('tests/Fixtures/real/'.$filename);

    if (! is_file($path)) {
        test()->markTestSkipped('Place '.$filename.' in tests/Fixtures/real/ to run this check.');
    }

    $passwordPath = preg_replace('/\.pdf$/i', '.password', $path);
    $password = is_string($passwordPath) && is_file($passwordPath)
        ? trim((string) file_get_contents($passwordPath))
        : null;

    $table = app(ExtractStatementTable::class)->handle(
        $path,
        ($password !== null && $password !== '') ? $password : null,
    );
    $mapping = app(MappingSuggester::class)->suggest($table);
    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect(count($table->rows))->toBeGreaterThanOrEqual($minRows)
        ->and($parsed->reconciliationMatchPercent)->toBeGreaterThanOrEqual($minReconcile)
        ->and($table->headerCells)->toContain('Date')
        ->and($table->headerCells)->toContain('Description');
})->with('real_statement_pdfs');
