<?php

use App\Services\Table\HeaderRowFinder;

it('detects cbi two-line header continuations', function () {
    $finder = new HeaderRowFinder;

    expect($finder->isContinuationLine('          Date       Code   Number'))->toBeTrue()
        ->and($finder->isContinuationLine('Date Code Number'))->toBeTrue()
        ->and($finder->isContinuationLine('01/04/2025 01/04/2025 RECOVERY 30,000.00'))->toBeFalse();
});

it('merges continuation words without dropping them on collisions', function () {
    $finder = new HeaderRowFinder;

    $merged = $finder->mergeHeaderLines(
        'Value     Branch',
        '     Date Code  ',
    );

    expect($merged)->toContain('Value')
        ->and($merged)->toContain('Date')
        ->and($merged)->toContain('Branch')
        ->and($merged)->toContain('Code');
});

it('prefers the transaction header over repeating address footers', function () {
    $finder = new HeaderRowFinder;

    $pages = [
        <<<'TXT'
Account Statement
Date                Transaction ID                          Particulars                                                    Debit              Credit           Balance
09-10-2025          PH510092196306987                       CKYC_THANKS_REGISTRATION                                       -                  21.00            21.00
Airtel Payments Bank Ltd.
Registered Office Address: Bharti Crescent, 1, Nelson Mandela Road, Vasant Kunj, Phase - II, New Delhi – 110070 CIN: U65100DL2010PLC201058
GST Number: 06AAICA4398J1ZA | HSN Code: 997119
TXT,
        <<<'TXT'
Date                Transaction ID                          Particulars                                                    Debit             Credit     Balance
26-10-2025          PH510261080372352                       PAYMENT MADE VIA UPI                                           40.00             -          711.00
Registered Office Address: Bharti Crescent, 1, Nelson Mandela Road, Vasant Kunj, Phase - II, New Delhi – 110070 CIN: U65100DL2010PLC201058
TXT,
        <<<'TXT'
Date                Transaction ID                          Particulars                                                    Debit             Credit     Balance
01-11-2025          PH511011080372999                       MONEY LOADED SUCCESSFULLY                                      -                  100.00          811.00
Registered Office Address: Bharti Crescent, 1, Nelson Mandela Road, Vasant Kunj, Phase - II, New Delhi – 110070 CIN: U65100DL2010PLC201058
TXT,
    ];

    $header = $finder->find($pages);

    expect($header)->not->toBeNull()
        ->and($header['score'])->toBeGreaterThan(5)
        ->and(strtolower($header['line']))->toContain('particulars')
        ->and(strtolower($header['line']))->not->toContain('registered office');
});

it('does not score short synonyms inside unrelated words', function () {
    $finder = new HeaderRowFinder;

    expect($finder->scoreLine('Registered Office Address: Bharti Crescent, New Delhi'))->toBeLessThan(2)
        ->and($finder->scoreLine('Date Particulars Debit Credit Balance'))->toBeGreaterThan(5);
});
