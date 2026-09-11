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

it('keeps cbi poppler layout columns coherent instead of hallucinated slices', function () {
    // Real CBI Poppler output uses a two-line header (Value/Date, Branch/Code, Cheque/Number).
    $text = <<<'TXT'
Central Bank of India
Post Date Value      Branch Cheque Transaction Description                 Debit        Credit       Balance
          Date       Code   Number
01/04/2025 01/04/2025 621         RECOVERY OF PROCESSING CHARGES          30,000.00                 90,46,057.06 DR
01/04/2025 01/04/2025 621         GST                                      5,400.00                 90,51,457.06 DR
01/04/2025 01/04/2025 621         RTGS TGSHI LIGHT IMPEX                  10,00,000.00             80,51,457.06 DR
02/04/2025 02/04/2025 621         IMPS P2A509207223435                                 88,000.00    69,63,768.58 DR
02/04/2025 02/04/2025 621 006982  NEFT RBI                                             23,500.00    81,87,268.58 DR
TXT;

    $table = app(ExtractStatementTable::class)->fromExtractedText(
        new ExtractedText([$text], 'cbi-poppler.pdf', 'poppler'),
    );

    expect($table->rows)->toHaveCount(5)
        ->and($table->rows[0][0])->toBe('01/04/2025')
        ->and($table->rows[0][1])->toContain('RECOVERY OF PROCESSING')
        ->and($table->rows[0][1])->toStartWith('621')
        ->and(implode(' | ', $table->rows[0]))->not->toMatch('/(?:^|\s|\|)\/\d{2}\//')
        ->and(implode(' | ', $table->rows[0]))->not->toMatch('/(?:^|\s|\|),\d/');
});
