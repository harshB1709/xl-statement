<?php

use App\Services\Table\ColumnBoundaryDetector;

it('merges cbi-style multi-word headers for poppler layout', function () {
    $header = 'Post Date Value Date Branch Cheque Transaction Description     Debit      Credit     Balance';

    $boundaries = (new ColumnBoundaryDetector)->detect($header, [
        '01/04/2025 01/04/2025 0123  456789  UPI/ACME/123              1,000.00               9,000.00 DR',
    ]);

    $headers = array_map(static fn (array $boundary): string => $boundary['header_text'], $boundaries);

    expect($headers)->toContain('Post Date')
        ->and($headers)->toContain('Value Date')
        ->and($headers)->toContain('Transaction Description')
        ->and($headers)->not->toContain('Post')
        ->and($headers)->not->toContain('Transaction');
});
