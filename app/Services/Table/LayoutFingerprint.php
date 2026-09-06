<?php

namespace App\Services\Table;

class LayoutFingerprint
{
    /**
     * @param  list<string>  $headerCells
     */
    public function make(array $headerCells, int $columnCount, ?string $bankName = null): string
    {
        $normalized = array_map(function (string $cell): string {
            $cell = strtolower($cell);
            $cell = preg_replace('/[^a-z0-9\/\s]+/', '', $cell) ?? $cell;
            $cell = preg_replace('/\s+/', ' ', $cell) ?? $cell;

            return trim($cell);
        }, $headerCells);

        $payload = implode('|', $normalized).'|'.$columnCount.'|'.strtolower((string) $bankName);

        return sha1($payload);
    }
}
