<?php

namespace App\Services\Table;

class HeaderRowFinder
{
    /**
     * @var list<string>
     */
    private array $synonyms = [
        'date', 'txn date', 'transaction date', 'value date', 'val date', 'post date',
        'narration', 'description', 'particulars', 'details', 'remarks', 'transaction details', 'transaction description',
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
            // Prefer real table headers (high synonym score) over footers that
            // merely repeat on every page — e.g. Airtel "Registered Office…".
            $scoreCmp = $b['best']['score'] <=> $a['best']['score'];

            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return $b['occurrences'] <=> $a['occurrences'];
        });

        return $candidates[0]['best'];
    }

    public function scoreLine(string $line): int
    {
        $normalized = $this->normalize($line);
        $score = 0;

        foreach ($this->synonyms as $synonym) {
            if ($this->containsSynonym($normalized, $synonym)) {
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
            || str_contains($normalized, 'value date')
            || str_contains($normalized, 'post date')) {
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

    /**
     * CBI Poppler headers span two lines, e.g. "Value" over "Date", "Branch" over "Code".
     */
    public function isContinuationLine(string $line): bool
    {
        $normalized = $this->normalize($line);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\d{1,2}[\/\-]\d{1,2}([\/\-]\d{2,4})?/', $normalized) === 1) {
            return false;
        }

        if (preg_match('/\d{1,3}(?:,\d{2,3})+(?:\.\d{2})?|\d+\.\d{2}/', $normalized) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $known = ['date', 'code', 'number', 'no', 'num', 'particulars', 'details'];
        $hits = 0;

        foreach ($tokens as $token) {
            if (in_array($token, $known, true)) {
                $hits++;
            }
        }

        return $hits >= 2 || ($hits >= 1 && count($tokens) <= 4 && $this->scoreLine($line) < 2);
    }

    public function mergeHeaderLines(string $top, string $bottom): string
    {
        $length = max(strlen($top), strlen($bottom));
        $top = str_pad($top, $length);
        $bottom = str_pad($bottom, $length);
        $merged = '';
        $pending = '';

        for ($index = 0; $index < $length; $index++) {
            $a = $top[$index];
            $b = $bottom[$index];

            if ($a === ' ' && $b !== ' ') {
                if ($pending !== '') {
                    $merged .= $pending;
                    $pending = '';
                }
                $merged .= $b;
            } elseif ($a !== ' ' && $b !== ' ') {
                $merged .= $a;
                $pending .= $b;
            } elseif ($a !== ' ') {
                $merged .= $a;
            } else {
                if ($pending !== '') {
                    $merged .= $pending;
                    $pending = '';
                }
                $merged .= ' ';
            }
        }

        if ($pending !== '') {
            $merged .= $pending;
        }

        return rtrim($merged);
    }

    public function normalize(string $line): string
    {
        $line = strtolower($line);
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return trim($line);
    }

    /**
     * Match header synonyms as whole tokens/phrases so short ones like "dr"/"cr"
     * do not hit inside "address" / "crescent".
     */
    private function containsSynonym(string $normalizedLine, string $synonym): bool
    {
        $quoted = preg_quote($synonym, '/');

        return preg_match('/(?:^|[^a-z0-9])'.$quoted.'(?:[^a-z0-9]|$)/', $normalizedLine) === 1;
    }
}
