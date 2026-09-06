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

        $normal = $this->score($transactions, false);
        $swapped = $this->score($transactions, true);

        return [
            'match_percent' => $normal['match_percent'],
            'mismatched_indexes' => $normal['mismatched_indexes'],
            'swap_suggested' => $swapped['match_percent'] >= 95.0
                && $swapped['match_percent'] > $normal['match_percent'] + 5,
        ];
    }

    /**
     * @param  list<Transaction>  $transactions
     * @return array{match_percent: float, mismatched_indexes: list<int>}
     */
    private function score(array $transactions, bool $swapDebitCredit): array
    {
        $mismatched = [];
        $checked = 0;
        $matched = 0;
        $previousBalance = null;

        foreach ($transactions as $index => $transaction) {
            if ($transaction->balance === null) {
                continue;
            }

            if ($previousBalance === null) {
                $previousBalance = $transaction->balance;

                continue;
            }

            $debit = $transaction->debit ?? 0.0;
            $credit = $transaction->credit ?? 0.0;

            if ($swapDebitCredit) {
                [$debit, $credit] = [$credit, $debit];
            }

            $expected = round($previousBalance + $credit - $debit, 2);
            $checked++;

            if (abs($expected - $transaction->balance) <= 0.01) {
                $matched++;
            } else {
                $mismatched[] = $index;
            }

            $previousBalance = $transaction->balance;
        }

        return [
            'match_percent' => $checked === 0 ? 100.0 : round(($matched / $checked) * 100, 1),
            'mismatched_indexes' => $mismatched,
        ];
    }
}
