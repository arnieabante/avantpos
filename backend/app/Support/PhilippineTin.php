<?php

namespace App\Support;

/**
 * BIR Taxpayer Identification Number: 9 digits, shown as 123-456-789.
 * The branch code (000 / 00000) is stored separately.
 */
class PhilippineTin
{
    /**
     * Returns "123-456-789" for 9 digits (or 12/14 digits including a branch
     * code, which is dropped), the input unchanged when it cannot be parsed
     * (so validation reports it), or null when empty.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $input);

        if (! in_array(strlen($digits), [9, 12, 14], true)) {
            return trim($input);
        }

        $digits = substr($digits, 0, 9);

        return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3);
    }
}
