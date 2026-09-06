<?php

namespace App\Support;

class Utf8
{
    public static function sanitize(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_scrub')) {
            return mb_scrub($value, 'UTF-8');
        }

        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $cleaned === false ? '' : $cleaned;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function sanitizeList(array $values): array
    {
        return array_map(static fn (string $value): string => self::sanitize($value), $values);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    public static function sanitizeRows(array $rows): array
    {
        return array_map(static fn (array $row): array => self::sanitizeList($row), $rows);
    }
}
