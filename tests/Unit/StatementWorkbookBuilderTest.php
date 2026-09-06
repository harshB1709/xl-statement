<?php

use App\Data\ExportOptions;
use App\Data\ParsedStatement;
use App\Data\Transaction;
use App\Services\Excel\NumberFormats;
use App\Services\Excel\StatementWorkbookBuilder;
use Carbon\CarbonImmutable;
use ZipArchive;

it('writes excel date serials, bank, and display source file', function () {
    $path = sys_get_temp_dir().'/xl-statement-workbook-format.xlsx';
    @unlink($path);

    $statement = new ParsedStatement(
        transactions: [
            new Transaction(
                date: CarbonImmutable::parse('2025-05-14'),
                valueDate: null,
                description: 'UPI',
                reference: null,
                debit: 1500.0,
                credit: null,
                balance: 8500.0,
                extras: [],
                page: 1,
                sourceFile: 'AcctStatement.pdf',
                bank: 'Axis Bank',
            ),
        ],
        bank: 'Axis Bank',
        sourceFile: 'AcctStatement.pdf',
    );

    (new StatementWorkbookBuilder)
        ->build([$statement], new ExportOptions(
            outputPath: $path,
            includeSummary: false,
            includeSourceColumns: true,
            excelDateFormat: 'dd-mm-yyyy',
        ))
        ->save($path);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = $zip->getFromName('xl/styles.xml');
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    $expectedSerial = (float) CarbonImmutable::parse('1899-12-30')
        ->startOfDay()
        ->diffInDays(CarbonImmutable::parse('2025-05-14')->startOfDay(), false);

    expect($sheet)->toContain('<v>'.$expectedSerial.'</v>')
        ->and($styles)->toContain(NumberFormats::date('dd-mm-yyyy'))
        ->and($styles)->toContain(NumberFormats::standard())
        ->and($shared)->toContain('AcctStatement.pdf')
        ->and($shared)->toContain('Axis Bank')
        ->and($shared)->not->toContain('14-05-2025');
});

it('omits unchecked export columns from the sheet', function () {
    $path = sys_get_temp_dir().'/xl-statement-workbook-columns.xlsx';
    @unlink($path);

    $statement = new ParsedStatement(
        transactions: [
            new Transaction(
                date: CarbonImmutable::parse('2025-05-14'),
                valueDate: CarbonImmutable::parse('2025-05-15'),
                description: 'UPI',
                reference: 'REF1',
                debit: 1500.0,
                credit: null,
                balance: 8500.0,
                extras: [],
                page: 1,
                sourceFile: 'AcctStatement.pdf',
                bank: 'Axis Bank',
            ),
        ],
        bank: 'Axis Bank',
        sourceFile: 'AcctStatement.pdf',
    );

    (new StatementWorkbookBuilder)
        ->build([$statement], new ExportOptions(
            outputPath: $path,
            includeSummary: false,
            columns: ['date', 'description', 'debit', 'balance'],
        ))
        ->save($path);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    expect($shared)->toContain('Date')
        ->and($shared)->toContain('Description')
        ->and($shared)->toContain('Debit')
        ->and($shared)->toContain('Balance')
        ->and($shared)->not->toContain('Value Date')
        ->and($shared)->not->toContain('Credit')
        ->and($shared)->not->toContain('Source File');
});
