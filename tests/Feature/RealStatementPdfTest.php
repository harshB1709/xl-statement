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
]);

it('extracts and reconciles real statement pdfs', function (string $filename, int $minRows, float $minReconcile) {
    $path = base_path('tests/Fixtures/real/'.$filename);

    if (! is_file($path)) {
        test()->markTestSkipped('Place '.$filename.' in tests/Fixtures/real/ to run this check.');
    }

    $table = app(ExtractStatementTable::class)->handle($path);
    $mapping = app(MappingSuggester::class)->suggest($table);
    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect(count($table->rows))->toBeGreaterThanOrEqual($minRows)
        ->and($parsed->reconciliationMatchPercent)->toBeGreaterThanOrEqual($minReconcile)
        ->and($table->headerCells)->toContain('Date')
        ->and($table->headerCells)->toContain('Description');
})->with('real_statement_pdfs');
