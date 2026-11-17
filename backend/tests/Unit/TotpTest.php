<?php

use App\Support\Security\Totp;

/*
| RFC 6238 test vectors (SHA-1, secret "12345678901234567890"), 6-digit codes:
| the last six digits of the RFC's 8-digit values.
*/

const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")

it('produces the RFC 6238 codes', function (int $time, string $code) {
    expect(Totp::code(RFC_SECRET, $time))->toBe($code);
})->with([
    [59, '287082'], [1111111109, '081804'], [1111111111, '050471'],
    [1234567890, '005924'], [2000000000, '279037'], [20000000000, '353130'],
]);

it('accepts the current code with a small clock drift, and nothing else', function () {
    $now = 1_800_000_000;
    $code = Totp::code(RFC_SECRET, $now);
    expect(Totp::verify(RFC_SECRET, $code, $now))->toBeTrue()
        ->and(Totp::verify(RFC_SECRET, $code, $now + 30))->toBeTrue()   // phone one step behind
        ->and(Totp::verify(RFC_SECRET, $code, $now + 120))->toBeFalse()
        ->and(Totp::verify(RFC_SECRET, '000000', $now))->toBe(Totp::code(RFC_SECRET, $now) === '000000')
        ->and(Totp::verify(RFC_SECRET, 'abcdef', $now))->toBeFalse()
        ->and(Totp::verify(RFC_SECRET, '', $now))->toBeFalse();
});

it('makes random base32 secrets and an otpauth link', function () {
    $a = Totp::secret();
    expect($a)->toMatch('/^[A-Z2-7]{16,}$/')->not->toBe(Totp::secret());
    expect(Totp::uri($a, 'admin@avantpos.ph'))->toStartWith('otpauth://totp/')->toContain('secret='.$a)->toContain('issuer=');
});
