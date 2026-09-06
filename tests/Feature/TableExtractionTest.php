<?php

use App\Actions\ApplyColumnMapping;
use App\Actions\ExtractStatementTable;
use App\Data\ColumnMapping;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Services\Pdf\ExtractedText;
use App\Services\Pdf\PdfTextExtractor;
use App\Services\Pdf\PreferPhpPdfTextExtractor;
use App\Services\Table\MappingSuggester;

it('extracts a separate debit credit layout from text', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/separate-dr-cr/input.txt'));
    $extracted = new ExtractedText([$text], 'fixture.pdf');

    $table = app(ExtractStatementTable::class)->fromExtractedText($extracted);

    expect($table->headerCells)->toContain('Date')
        ->and($table->headerCells)->toContain('Narration')
        ->and(count($table->rows))->toBeGreaterThanOrEqual(4);

    $mapping = app(MappingSuggester::class)->suggest($table);

    expect($mapping->targets)->toContain(TargetField::Date)
        ->and($mapping->targets)->toContain(TargetField::Debit)
        ->and($mapping->targets)->toContain(TargetField::Credit)
        ->and($mapping->targets)->toContain(TargetField::Balance);

    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect($parsed->transactions)->not->toBeEmpty()
        ->and($parsed->reconciliationMatchPercent)->toBeGreaterThan(50);
});

it('maps single amount with marker layout', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/single-amount-marker/input.txt'));
    $table = app(ExtractStatementTable::class)->fromExtractedText(new ExtractedText([$text], 'marker.pdf'));

    $mapping = new ColumnMapping(
        targets: [
            0 => TargetField::Date,
            1 => TargetField::Description,
            2 => TargetField::Amount,
            3 => TargetField::DrCrMarker,
            4 => TargetField::Balance,
        ],
        dateFormat: 'd-m-Y',
        amountStyle: AmountStyle::SingleWithMarker,
    );

    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect($parsed->transactions)->toHaveCount(3)
        ->and($parsed->transactions[0]->debit)->toBe(450.0)
        ->and($parsed->transactions[1]->credit)->toBe(50000.0);
});

it('binds a portable pdf text extractor', function () {
    expect(app(PdfTextExtractor::class))->toBeInstanceOf(PreferPhpPdfTextExtractor::class);
});
