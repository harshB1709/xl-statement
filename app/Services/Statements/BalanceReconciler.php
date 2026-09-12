<?php

namespace App\Services\Statements;

use App\Data\Transaction;

class BalanceReconciler
{
    /**
     * @param  list<Transaction>  $transactions
     * @return array{match_percent: float, mismatched_indexes: list<int>, swap_suggested: bool}
     */
    public function reconcile(array $transactions): array
    {
        if ($transactions === []) {
            return [
                'match_percent' => 100.0,
                'mismatched_indexes' => [],
                'swap_suggested' => false,
            ];
        }

        $forward = $this->score($transactions, false, false);
        $reverse = $this->score($transactions, false, true);
        $normal = $forward['match_percent'] >= $reverse['match_percent'] ? $forward : $reverse;

        $swappedForward = $this->score($transactions, true, false);
        $swappedReverse = $this->score($transactions, true, true);
        $swappedBest = max($swappedForward['match_percent'], $swappedReverse['match_percent']);

        return [
            'match_percent' => $normal['match_percent'],
            'mismatched_indexes' => $normal['mismatched_indexes'],
            'swap_suggested' => $swappedBest >= 95.0
                && $swappedBest > $normal['match_percent'] + 5,
        ];
    }

    /**
     * @param  list<Transaction>  $transactions
     * @return array{match_percent: float, mismatched_indexes: list<int>}
     */
    private function score(array $transactions, bool $swapDebitCredit, bool $reverseChronological): array
    {
        $mismatched = [];
        $checked = 0;
        $matched = 0;
        $previousBalance = null;
        $previousDebit = 0.0;
        $previousCredit = 0.0;

        foreach ($transactions as $index => $transaction) {
            if ($transaction->balance === null) {
                continue;
            }

            $debit = $transaction->debit ?? 0.0;
            $credit = $transaction->credit ?? 0.0;

            if ($swapDebitCredit) {
                [$debit, $credit] = [$credit, $debit];
            }

            if ($previousBalance === null) {
                $previousBalance = $transaction->balance;
                $previousDebit = $debit;
                $previousCredit = $credit;

                continue;
            }

            // Forward: apply this row's txn to the previous balance.
            // Reverse (newest→oldest): the balance drop equals the *previous*
            // (newer) txn being undone, not the current older row's amount.
            $expected = $reverseChronological
                ? round($previousBalance - $previousCredit + $previousDebit, 2)
                : round($previousBalance + $credit - $debit, 2);
            $checked++;

            if (abs($expected - $transaction->balance) <= 0.01) {
                $matched++;
            } else {
                $mismatched[] = $index;
            }

            $previousBalance = $transaction->balance;
            $previousDebit = $debit;
            $previousCredit = $credit;
        }

        return [
            'match_percent' => $checked === 0 ? 100.0 : round(($matched / $checked) * 100, 1),
            'mismatched_indexes' => $mismatched,
        ];
    }
}
