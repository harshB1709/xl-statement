<?php

use App\Services\Statements\DateNormalizer;

it('parses common indian date formats', function () {
    $normalizer = new DateNormalizer;

    expect($normalizer->parse('01/04/2025', 'd/m/Y')?->toDateString())->toBe('2025-04-01')
        ->and($normalizer->parse('01-Apr-2025', 'd-M-Y')?->toDateString())->toBe('2025-04-01')
        ->and($normalizer->parse('2025-04-01', 'Y-m-d')?->toDateString())->toBe('2025-04-01');
});

it('parses spaced month names even when the mapped format differs', function () {
    $normalizer = new DateNormalizer;

    expect($normalizer->parse('07 Sep 2025', 'd/m/Y')?->toDateString())->toBe('2025-09-07')
        ->and($normalizer->parse('7 September 2025', 'd/m/Y')?->toDateString())->toBe('2025-09-07')
        ->and($normalizer->parse('07-Sep-2025', 'd/m/Y')?->toDateString())->toBe('2025-09-07');
});

it('detects dd/mm format from samples', function () {
    $result = (new DateNormalizer)->detectFormat([
        '13/01/2025',
        '01/02/2025',
        '28/02/2025',
    ]);

    expect($result['format'])->toBe('d/m/Y')
        ->and($result['dates'][0]?->toDateString())->toBe('2025-01-13');
});

it('detects spaced month-name dates', function () {
    $result = (new DateNormalizer)->detectFormat([
        '07 Sep 2025',
        '08 Sep 2025',
        '01 Oct 2025',
    ]);

    expect($result['format'])->toBe('d M Y')
        ->and($result['dates'][0]?->toDateString())->toBe('2025-09-07');
});
