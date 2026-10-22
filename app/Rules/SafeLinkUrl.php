<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Hanya menerima path relatif (diawali "/" tetapi bukan "//") atau URL http(s).
 *
 * Mencegah nilai seperti "javascript:..." dipasang sebagai tautan tombol.
 */
class SafeLinkUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Format tautan tombol tidak valid.');

            return;
        }

        $isRelativePath = str_starts_with($value, '/') && ! str_starts_with($value, '//');

        $isHttpUrl = preg_match('#^https?://#i', $value) === 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false;

        if (! $isRelativePath && ! $isHttpUrl) {
            $fail('Format tautan tombol tidak valid. Gunakan path relatif (mis. /katalog) atau URL http(s).');
        }
    }
}
