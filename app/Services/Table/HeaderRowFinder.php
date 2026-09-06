<?php

namespace App\Services\Table;

class HeaderRowFinder
{
    /**
     * @var list<string>
     */
    private array $synonyms = [
        'date', 'txn date', 'transaction date', 'value date', 'val date',
        'narration', 'description', 'particulars', 'details', 'remarks', 'transaction details',
        'chq', 'cheque', 'ref', 'reference', 'utr', 'chq/ref', 'chq / ref',
        'withdrawal', 'debit', 'withdrawals', 'dr',
        'deposit', 'credit', 'deposits', 'cr',
        'amount', 'balance', 'closing balance', 'running bal', 'running balance',
    ];

    /**
     * @param  list<string>  $pages
     * @return array{page_index: int, line_index: int, line: string, score: int}|null
     */
    public function find(array $pages): ?array
    {
        $candidates = [];

        foreach ($pages as $pageIndex => $page) {
            $lines = preg_split("/\r\n|\n|\r/", $page) ?: [];

            foreach ($lines as $lineIndex => $line) {
                $score = $this->scoreLine($line);

                if ($score < 2) {
                    continue;
                }

                $normalized = $this->normalize($line);
                $key = $normalized;

                $candidates[$key] ??= [
                    'normalized' => $normalized,
                    'occurrences' => 0,
                    'best' => null,
                ];

                $candidates[$key]['occurrences']++;

                $current = [
                    'page_index' => $pageIndex,
                    'line_index' => $lineIndex,
                    'line' => $line,
                    'score' => $score,
                ];

                if ($candidates[$key]['best'] === null || $score > $candidates[$key]['best']['score']) {
                    $candidates[$key]['best'] = $current;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $occurrenceCmp = $b['occurrences'] <=> $a['occurrences'];

            if ($occurrenceCmp !== 0) {
                return $occurrenceCmp;
            }

            return $b['best']['score'] <=> $a['best']['score'];
        });

        return $candidates[0]['best'];
    }

    public function scoreLine(string $line): int
    {
        $normalized = $this->normalize($line);
        $score = 0;

        foreach ($this->synonyms as $synonym) {
            if (str_contains($normalized, $synonym)) {
                $score++;
            }
        }

        // Prefer transaction table headers over summary strip headers.
        if (str_contains($normalized, 'particulars')
            || str_contains($normalized, 'narration')
            || str_contains($normalized, 'description')) {
            $score += 3;
        }

        if (str_contains($normalized, 'transaction date')
            || str_contains($normalized, 'tran date')
            || str_contains($normalized, 'value date')) {
            $score += 2;
        }

        // Summary totals strip (Opening/Total Debit/Total Credit/Closing) without txn columns.
        if ((str_contains($normalized, 'opening balance') || str_contains($normalized, 'closing balance'))
            && str_contains($normalized, 'total')
            && ! str_contains($normalized, 'particulars')
            && ! str_contains($normalized, 'narration')
            && ! str_contains($normalized, 'description')) {
            $score = min($score, 1);
        }

        return $score;
    }

    public function normalize(string $line): string
    {
        $line = strtolower($line);
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return trim($line);
    }
}
