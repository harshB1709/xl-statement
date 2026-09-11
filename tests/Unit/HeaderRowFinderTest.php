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
