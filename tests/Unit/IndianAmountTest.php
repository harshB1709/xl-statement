<?php

use App\Services\Statements\IndianAmount;

it('parses indian grouped amounts', function (string $raw, float $value, ?string $marker) {
    $parsed = (new IndianAmount)->parse($raw);

    expect($parsed)->not->toBeNull()
        ->and($parsed->value)->toBe($value)
        ->and($parsed->marker)->toBe($marker);
})->with([
    ['1,23,456.78', 123456.78, null],
    ['1,23,456.78 Cr', 123456.78, 'Cr'],
    ['12,345.00 Dr', 12345.0, 'Dr'],
    ['(1,234.00)', -1234.0, null],
    ['-1,234.50', -1234.5, null],
    ['₹ 2,500.00', 2500.0, null],
    ['Rs.1,000.00', 1000.0, null],
]);

it('returns null for blanks', function () {
    expect((new IndianAmount)->parse(''))->toBeNull()
        ->and((new IndianAmount)->parse('-'))->toBeNull();
});
