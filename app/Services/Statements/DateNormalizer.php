<?php

namespace App\Services\Statements;

use Carbon\CarbonImmutable;
use Throwable;

class DateNormalizer
{
    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return [
            'd/m/Y',
            'd-m-Y',
            'd/m/y',
            'd-m-y',
            'd-M-Y',
            'd M Y',
            'j M Y',
            'd-M-y',
            'd M y',
            'Y-m-d',
            'm/d/Y',
            'd/m/Y H:i:s',
            'd-m-Y H:i:s',
        ];
    }

    /**
     * @param  list<string>  $candidates
     * @return array{format: string, dates: list<?CarbonImmutable>}
     */
    public function detectFormat(array $candidates): array
    {
        $bestFormat = 'd/m/Y';
        $bestScore = -1;
        $bestDates = [];

        foreach ($this->formats() as $format) {
            $dates = [];
            $score = 0;

            foreach ($candidates as $candidate) {
                $parsed = $this->parseWithFormat($candidate, $format);

                if ($parsed !== null) {
                    $score++;
                }

                $dates[] = $parsed;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestFormat = $format;
                $bestDates = $dates;
            }
        }

        return [
            'format' => $bestFormat,
            'dates' => $bestDates,
        ];
    }

    public function parse(?string $raw, string $format = 'd/m/Y'): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        if ($value === '') {
            return null;
        }

        $parsed = $this->parseWithFormat($value, $format);

        if ($parsed !== null) {
            return $parsed;
        }

        foreach ($this->formats() as $fallback) {
            if ($fallback === $format) {
                continue;
            }

            $parsed = $this->parseWithFormat($value, $fallback);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->parseLoose($value);
    }

    private function parseWithFormat(string $value, string $format): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);

            if ($date === false) {
                return null;
            }

            $errors = CarbonImmutable::getLastErrors();

            if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                return null;
            }

            return $date;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseLoose(string $value): ?CarbonImmutable
    {
        if (preg_match('/\d/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value)->startOfDay();

            if ($date->year < 1990 || $date->year > 2100) {
                return null;
            }

            return $date;
        } catch (Throwable) {
            return null;
        }
    }
}
