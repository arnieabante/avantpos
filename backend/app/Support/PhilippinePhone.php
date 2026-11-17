<?php

namespace App\Support;

/**
 * Philippine phone numbers.
 *
 * Mobile numbers are stored in E.164 form: +639XXXXXXXXX.
 * Accepted inputs: 09171234567, 9171234567, 639171234567, +63 917 123 4567, 0917-123-4567.
 * Landlines (e.g. 02-8123-4567, 032 234 5678) are accepted and kept as digits with a leading 0.
 */
class PhilippinePhone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input);

        // Mobile
        if (preg_match('/^(?:63|0)?(9\d{9})$/', $digits, $m)) {
            return '+63'.$m[1];
        }

        // Landline: area code (2-4 digits incl. leading 0) + 7-8 digit number
        if (preg_match('/^(?:63)?0?([2-8]\d{7,9})$/', $digits, $m)) {
            return '0'.$m[1];
        }

        return $input; // leave as-is so validation reports it
    }

    public static function isValid(?string $normalized): bool
    {
        return $normalized !== null
            && (preg_match('/^\+639\d{9}$/', $normalized) === 1 || preg_match('/^0[2-8]\d{7,9}$/', $normalized) === 1);
    }

    public static function isMobile(?string $normalized): bool
    {
        return $normalized !== null && preg_match('/^\+639\d{9}$/', $normalized) === 1;
    }
}
