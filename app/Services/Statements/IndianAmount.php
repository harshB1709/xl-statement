<?php

namespace App\Services\Statements;

use App\Data\ParsedAmount;

class IndianAmount
{
    public function parse(?string $raw): ?ParsedAmount
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '' || $value === '-' || $value === '—') {
            return null;
        }

        $marker = null;

        if (preg_match('/\b(cr|dr)\b\.?/i', $value, $matches) === 1) {
            $marker = str_starts_with(strtoupper($matches[1]), 'C') ? 'Cr' : 'Dr';
            $value = preg_replace('/\b(cr|dr)\b\.?/i', '', $value) ?? $value;
        } elseif (preg_match('/\b([cd])\b\.?/i', $value, $matches) === 1) {
            $marker = strtoupper($matches[1]) === 'C' ? 'Cr' : 'Dr';
            $value = preg_replace('/\b([cd])\b\.?/i', '', $value) ?? $value;
        }

        $value = preg_replace('/^(₹|inr|rs\.?)\s*/iu', '', trim($value)) ?? $value;
        $value = trim($value);

        $negative = false;

        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $negative = true;
            $value = substr($value, 1, -1);
        }

        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = ltrim($value, '-');
        }

        if (str_ends_with($value, '-')) {
            $negative = true;
            $value = rtrim($value, '-');
        }

        $value = str_replace([',', ' '], '', trim($value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        if ($negative) {
            $amount = -abs($amount);
        }

        return new ParsedAmount($amount, $marker);
    }
}
