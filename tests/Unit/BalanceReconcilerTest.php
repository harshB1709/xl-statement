<?php

use App\Data\Transaction;
use App\Services\Statements\BalanceReconciler;
use Carbon\CarbonImmutable;

it('reports high match percent for consistent balances', function () {
    $transactions = [
        new Transaction(CarbonImmutable::parse('2025-04-01'), null, 'A', null, null, null, 10000.0, [], 1, 'a.pdf'),
        new Transaction(CarbonImmutable::parse('2025-04-02'), null, 'B', null, 1500.0, null, 8500.0, [], 1, 'a.pdf'),
        new Transaction(CarbonImmutable::parse('2025-04-03'), null, 'C', null, null, 500.0, 9000.0, [], 1, 'a.pdf'),
    ];

    $result = (new BalanceReconciler)->reconcile($transactions);

    expect($result['match_percent'])->toBe(100.0)
        ->and($result['mismatched_indexes'])->toBe([])
        ->and($result['swap_suggested'])->toBeFalse();
});

it('suggests swap when debit and credit are inverted', function () {
    $transactions = [
        new Transaction(CarbonImmutable::parse('2025-04-01'), null, 'A', null, null, null, 10000.0, [], 1, 'a.pdf'),
        new Transaction(CarbonImmutable::parse('2025-04-02'), null, 'B', null, null, 1500.0, 8500.0, [], 1, 'a.pdf'),
        new Transaction(CarbonImmutable::parse('2025-04-03'), null, 'C', null, 500.0, null, 9000.0, [], 1, 'a.pdf'),
    ];

    $result = (new BalanceReconciler)->reconcile($transactions);

    expect($result['swap_suggested'])->toBeTrue();
});

it('reconciles newest-first statements using reverse chronology', function () {
    // Newest→oldest: each balance undoes the previous (newer) txn.
    $transactions = [
        new Transaction(CarbonImmutable::parse('2025-11-22'), null, 'Int', null, null, 2123.0, 166935.29, [], 1, 'boi.pdf'),
        new Transaction(CarbonImmutable::parse('2025-11-22'), null, 'TDS', null, 213.0, null, 164812.29, [], 1, 'boi.pdf'),
        new Transaction(CarbonImmutable::parse('2025-11-04'), null, 'Interest', null, null, 975.0, 165025.29, [], 1, 'boi.pdf'),
    ];

    $result = (new BalanceReconciler)->reconcile($transactions);

    expect($result['match_percent'])->toBe(100.0)
        ->and($result['swap_suggested'])->toBeFalse();
});
