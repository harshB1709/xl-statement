<?php

namespace App\Services\Table;

class ColumnBoundaryDetector
{
    /**
     * @param  list<string>  $bodyLines
     * @return list<array{x_start: int, x_end: int, header_text: string}>
     */
    public function detect(string $headerLine, array $bodyLines): array
    {
        $headerTokens = $this->tokenize($headerLine);

        if ($headerTokens === []) {
            return $this->fallbackEvenColumns($headerLine, 5);
        }

        $maxWidth = strlen($headerLine);

        foreach ($bodyLines as $line) {
            $maxWidth = max($maxWidth, strlen($line));
        }

        $boundaries = [];

        foreach ($headerTokens as $index => $token) {
            $nextStart = $headerTokens[$index + 1]['start'] ?? ($maxWidth + 1);

            $boundaries[] = [
                'x_start' => $index === 0 ? 0 : $token['start'],
                'x_end' => $index === array_key_last($headerTokens)
                    ? $maxWidth
                    : max($token['end'], $nextStart - 1),
                'header_text' => $token['text'],
            ];
        }

        return $boundaries;
    }

    /**
     * @return list<array{text: string, start: int, end: int}>
     */
    private function tokenize(string $line): array
    {
        if (preg_match_all('/\S+/', $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $merged = [];

        foreach ($matches[0] as $match) {
            $text = $match[0];
            $start = (int) $match[1];
            $end = $start + strlen($text) - 1;

            if ($merged !== []) {
                $previous = &$merged[array_key_last($merged)];
                $combined = strtolower($previous['text'].' '.$text);

                if ($this->isMultiWordHeader($combined) && ($start - $previous['end']) <= 3) {
                    $previous['text'] .= ' '.$text;
                    $previous['end'] = $end;

                    continue;
                }
            }

            $merged[] = [
                'text' => $text,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $merged;
    }

    private function isMultiWordHeader(string $combined): bool
    {
        $known = [
            'value date', 'txn date', 'transaction date', 'val date', 'post date',
            'cheque no', 'chq no', 'ref no', 'chq/ref no', 'chq / ref no',
            'withdrawal amt', 'deposit amt', 'closing balance', 'running balance',
            'transaction details', 'transaction description',
            'branch code', 'cheque number',
        ];

        return in_array($combined, $known, true);
    }

    /**
     * @return list<array{x_start: int, x_end: int, header_text: string}>
     */
    private function fallbackEvenColumns(string $headerLine, int $count): array
    {
        $width = max(strlen($headerLine), 80);
        $colWidth = (int) floor($width / $count);
        $boundaries = [];

        for ($i = 0; $i < $count; $i++) {
            $boundaries[] = [
                'x_start' => $i * $colWidth,
                'x_end' => ($i === $count - 1) ? $width : (($i + 1) * $colWidth - 1),
                'header_text' => 'Column '.($i + 1),
            ];
        }

        return $boundaries;
    }
}
