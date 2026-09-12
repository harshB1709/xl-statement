<?php

namespace App\Services\Table;

class NoiseFilter
{
    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headerCells
     * @return list<list<string>>
     */
    public function filter(array $rows, array $headerCells): array
    {
        $normalizedHeader = $this->normalize(implode(' ', $headerCells));

        return array_values(array_filter($rows, function (array $row) use ($normalizedHeader): bool {
            $joined = $this->normalize(implode(' ', $row));

            if ($joined === '') {
                return false;
            }

            if ($joined === $normalizedHeader) {
                return false;
            }

            if ($this->isNoiseLine($joined)) {
                return false;
            }

            if (preg_match('/\b(opening balance|closing balance|brought forward|carried forward)\b/', $joined) === 1
                && preg_match('/\b(upi|neft|imps|rtgs|atm|pos|transfer|payment|salary|emi)\b/', $joined) !== 1) {
                return false;
            }

            if (preg_match('/^(total|totals)\b/', $joined) === 1) {
                return false;
            }

            // Drop rows whose date cell was polluted by a page footer merge.
            $first = $this->normalize((string) ($row[0] ?? ''));
            if ($first !== '' && $this->isNoiseLine($first)) {
                return false;
            }

            return true;
        }));
    }

    public function isNoiseLine(string $line): bool
    {
        $normalized = $this->normalize($line);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/page\s+\d+(\s+of\s+\d+)?/', $normalized) === 1) {
            return true;
        }

        if (str_contains($normalized, 'computer generated')
            || str_contains($normalized, 'disclaimer')
            || str_contains($normalized, 'registered office')
            || str_contains($normalized, 'gst number')
            || str_contains($normalized, 'cin:')
            || str_contains($normalized, 'help & support')
            || str_contains($normalized, 'wecare@')
            || str_contains($normalized, 'deposit insurance')
            || str_contains($normalized, 'dicgc')) {
            return true;
        }

        if (preg_match('/^note\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\bany discrepancy in the account statement\b/', $normalized) === 1
            || str_contains($normalized, 'found correct by you')
            || str_contains($normalized, 'bank never asks')
            || str_contains($normalized, 'do not share your atm')
            || str_contains($normalized, 'passwords with anyone')
            || str_contains($normalized, 'end of statement')
            || str_contains($normalized, 'total credit')
            || str_contains($normalized, 'total debit')
            || str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'grievance')) {
            return true;
        }

        // Bare bank legal-name footers without a transaction date.
        if (preg_match('/\b(payments bank ltd|mahindra bank ltd|bank ltd\.?)\b/', $normalized) === 1
            && preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}/', $normalized) !== 1) {
            return true;
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($value)) ?? $value);
    }
}
