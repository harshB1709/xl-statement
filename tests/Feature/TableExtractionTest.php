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

it('maps the last hdfc-style row without swallowing the statement summary', function () {
    $text = <<<'TXT'
HDFC Bank
Date Narration Chq./Ref.No. Value Dt Withdrawal Amt. Deposit Amt. Closing Balance
09/10/18 ACH D- HOME LOAN-38062210600110 0000005708866326 09/10/18 1,659.00 17,254.94
10/10/18 NHDF6774869440/SBI CARDS 0000182839032247 10/10/18 10,060.00 7,194.94
STATEMENT SUMMARY :-
Opening Balance Dr Count Cr Count Debits Credits Closing Bal
14,109.95 85 0 189,017.40 0.00 7,194.94
This is a computer generated statement and does
not require signature.
HDFC BANK LIMITED
TXT;

    $table = app(ExtractStatementTable::class)->fromExtractedText(new ExtractedText([$text], 'hdfc.pdf'));
    $mapping = app(MappingSuggester::class)->suggest($table);
    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);
    $last = $parsed->transactions[array_key_last($parsed->transactions)];

    expect($parsed->transactions)->toHaveCount(2)
        ->and($last->date?->toDateString())->toBe('2018-10-10')
        ->and($last->debit)->toBe(10060.0)
        ->and($last->credit)->toBeNull()
        ->and($last->balance)->toBe(7194.94)
        ->and($last->description)->toContain('SBI CARDS')
        ->and($last->description)->not->toContain('STATEMENT SUMMARY');
});

it('extracts canara deposit withdrawal wraps without preamble pollution', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/canara-wrap/input.txt'));

    $table = app(ExtractStatementTable::class)->fromExtractedText(new ExtractedText([$text], 'canara.pdf'));
    $mapping = app(MappingSuggester::class)->suggest($table);
    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect($table->bankName)->toBe('Canara Bank')
        ->and($table->headerCells)->toBe(['Date', 'Description', 'Debit', 'Credit', 'Balance'])
        ->and($parsed->transactions)->toHaveCount(4)
        ->and($parsed->transactions[0]->date?->toDateString())->toBe('2022-09-23')
        ->and($parsed->transactions[0]->debit)->toBe(100.0)
        ->and($parsed->transactions[0]->balance)->toBe(900.0)
        ->and($parsed->transactions[0]->description)->toContain('UPI/DR')
        ->and($parsed->transactions[0]->description)->not->toContain('Opening Balance')
        ->and($parsed->transactions[1]->credit)->toBe(50.0)
        ->and($parsed->transactions[2]->debit)->toBe(18.0)
        ->and($parsed->transactions[3]->debit)->toBe(100.0)
        ->and($parsed->reconciliationMatchPercent)->toBe(100.0);
});

it('extracts hdfc credit-card statements with signed amounts and fee percents', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/hdfc-cc/input.txt'));

    $table = app(ExtractStatementTable::class)->fromExtractedText(new ExtractedText([$text], 'hdfc-cc.pdf'));
    $mapping = app(MappingSuggester::class)->suggest($table);
    $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

    expect($table->bankName)->toBe('HDFC Bank')
        ->and($table->headerCells)->toBe(['Date', 'Description', 'Debit', 'Credit', 'Balance'])
        ->and($parsed->transactions)->toHaveCount(7)
        ->and($parsed->transactions[0]->credit)->toBe(112.42)
        ->and($parsed->transactions[1]->debit)->toBe(30842.0)
        ->and($parsed->transactions[3]->debit)->toBe(132.8)
        ->and($parsed->transactions[3]->description)->toStartWith('1.75% on all DCC Transaction')
        ->and($parsed->transactions[4]->credit)->toBe(2948.0)
        ->and($parsed->reconciliationMatchPercent)->toBe(100.0);
});
