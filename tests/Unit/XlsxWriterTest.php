<?php

use App\Services\Excel\NumberFormats;
use App\Services\Excel\XlsxWriter;
use ZipArchive;

it('writes a readable xlsx with indian number format', function () {
    $path = sys_get_temp_dir().'/xl-statement-test.xlsx';
    @unlink($path);

    (new XlsxWriter)
        ->addSheet(
            'Transactions',
            ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
            [
                ['01-04-2025', 'UPI', 1500.5, null, 8500.5],
                ['02-04-2025', 'Salary', null, 25000.0, 33500.5],
            ],
            [12, 40, 14, 14, 14],
            [
                2 => NumberFormats::indian(),
                3 => NumberFormats::indian(),
                4 => NumberFormats::indian(),
            ],
        )
        ->save($path);

    expect(file_exists($path))->toBeTrue();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = $zip->getFromName('xl/styles.xml');
    $zip->close();

    expect($sheet)->toContain('<v>1500.5</v>')
        ->and($sheet)->toContain('autoFilter')
        ->and($styles)->toContain('##\\,##\\,##\\,##0.00');
});
