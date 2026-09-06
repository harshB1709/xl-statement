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

            if (preg_match('/page\s+\d+\s+of\s+\d+/', $joined) === 1) {
                return false;
            }

            if (str_contains($joined, 'computer generated') || str_contains($joined, 'disclaimer')) {
                return false;
            }

            if (preg_match('/\b(opening balance|closing balance|brought forward|carried forward)\b/', $joined) === 1
                && preg_match('/\b(upi|neft|imps|rtgs|atm|pos|transfer|payment|salary|emi)\b/', $joined) !== 1) {
                return false;
            }

            if (preg_match('/^(total|totals)\b/', $joined) === 1) {
                return false;
            }

            return true;
        }));
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($value)) ?? $value);
    }
}
