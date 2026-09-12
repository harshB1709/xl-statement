<?php

use App\Services\Table\ColumnBoundaryDetector;
use App\Services\Table\RowSlicer;

it('starts a new row when the date sits after a serial number column', function () {
    $header = ' Sr No       Date            Remarks                                       Debit                Credit               Balance';
    $lines = [
        ' 1           28-03-2026      IO For 013253710000152                                                        1586.00         ₹ 390,817.29',
        ' 2           28-03-2026      IO For 013243710001026                                                        1648.00         ₹ 389,231.29',
        '                             continuation of remarks only',
    ];

    $boundaries = (new ColumnBoundaryDetector)->detect($header, $lines);
    $rows = (new RowSlicer)->slice($boundaries, $lines);

    expect($boundaries[0]['header_text'])->toBe('Sr No')
        ->and($rows)->toHaveCount(2)
        ->and($rows[0][1])->toBe('28-03-2026')
        ->and($rows[1][1])->toBe('28-03-2026')
        ->and($rows[0][2])->toContain('IO For 013253710000152')
        ->and($rows[1][2])->toContain('continuation of remarks only');
});

it('skips footer noise instead of merging it into the prior row', function () {
    $header = 'Date                Transaction ID     Particulars                          Debit      Credit     Balance';
    $lines = [
        '09-10-2025          PH510092196306987  CKYC_THANKS_REGISTRATION             -          21.00      21.00',
        'Registered Office Address: Bharti Crescent, 1, Nelson Mandela Road',
        '10-10-2025          PH510101317184930  PAYMENT MADE VIA UPI                 145.00     -         876.00',
    ];

    $boundaries = (new ColumnBoundaryDetector)->detect($header, $lines);
    $rows = (new RowSlicer)->slice($boundaries, $lines);

    expect($rows)->toHaveCount(2)
        ->and(implode(' ', $rows[0]))->not->toContain('Registered Office')
        ->and($rows[1][0])->toBe('10-10-2025');
});
