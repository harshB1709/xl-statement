<?php

namespace App\Services\Table;

use App\Support\Utf8;

class RowSlicer
{
    /**
     * @param  list<array{x_start: int, x_end: int, header_text: string}>  $boundaries
     * @param  list<string>  $lines
     * @return list<list<string>>
     */
    public function slice(array $boundaries, array $lines): array
    {
        $rows = [];
        $noiseFilter = new NoiseFilter;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($noiseFilter->isNoiseLine($line)) {
                continue;
            }

            $cells = [];

            foreach ($boundaries as $boundary) {
                $length = max(0, $boundary['x_end'] - $boundary['x_start'] + 1);
                $cells[] = Utf8::sanitize(trim(substr($line.str_repeat(' ', $boundary['x_end'] + 5), $boundary['x_start'], $length)));
            }

            if ($this->isContinuation($cells, $rows)) {
                $lastIndex = array_key_last($rows);

                foreach ($cells as $columnIndex => $cell) {
                    if ($cell === '') {
                        continue;
                    }

                    $rows[$lastIndex][$columnIndex] = Utf8::sanitize(trim($rows[$lastIndex][$columnIndex].' '.$cell));
                }

                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $cells
     * @param  list<list<string>>  $rows
     */
    private function isContinuation(array $cells, array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        // Date may sit in column 0 (Date | …) or column 1 (Sr No | Date | …).
        foreach (array_slice($cells, 0, 3) as $cell) {
            if ($cell !== '' && $this->isDateLike($cell)) {
                return false;
            }
        }

        $first = $cells[0] ?? '';

        if ($first === '') {
            return true;
        }

        return implode('', $cells) !== '';
    }

    private function isDateLike(string $value): bool
    {
        return preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Za-z]{3}\s+\d{2,4}/', $value) === 1;
    }
}
