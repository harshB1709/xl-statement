<?php

namespace App\Services\Excel;

class NumberFormats
{
    /**
     * Indian lakhs/crores grouping for Microsoft Excel.
     *
     * Apple Numbers (and some other apps) mis-render the escaped-comma
     * placeholders as leading commas (e.g. ",5,00000.00"). Prefer
     * {@see standard()} when the file may be opened outside Excel.
     */
    public static function indian(int $decimals = 2): string
    {
        $suffix = $decimals > 0 ? '.'.str_repeat('0', $decimals) : '';

        return '[>=10000000]##\,##\,##\,##0'.$suffix.';[>=100000]##\,##\,##0'.$suffix.';##,##0'.$suffix;
    }

    /**
     * Portable thousands grouping — works in Excel and Apple Numbers.
     */
    public static function standard(int $decimals = 2): string
    {
        $suffix = $decimals > 0 ? '.'.str_repeat('0', $decimals) : '';

        return '#,##0'.$suffix;
    }

    public static function amount(bool $indianFormat, int $decimals = 2): string
    {
        return $indianFormat ? self::indian($decimals) : self::standard($decimals);
    }

    public static function date(string $excelDateFormat = 'dd-mm-yyyy'): string
    {
        return match ($excelDateFormat) {
            'dd-mmm-yyyy' => 'DD-MMM-YYYY',
            'yyyy-mm-dd' => 'YYYY-MM-DD',
            default => 'DD-MM-YYYY',
        };
    }
}
