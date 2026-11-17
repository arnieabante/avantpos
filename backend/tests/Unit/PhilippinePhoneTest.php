<?php

use App\Support\PhilippinePhone;

it('normalizes Philippine mobile numbers to +63 format', function (string $input) {
    expect(PhilippinePhone::normalize($input))->toBe('+639171234567')
        ->and(PhilippinePhone::isMobile(PhilippinePhone::normalize($input)))->toBeTrue();
})->with([
    '09171234567',
    '9171234567',
    '639171234567',
    '+639171234567',
    '+63 917 123 4567',
    '0917-123-4567',
]);

it('keeps landline numbers with a leading zero', function () {
    expect(PhilippinePhone::normalize('(02) 8123-4567'))->toBe('0281234567')
        ->and(PhilippinePhone::isValid('0281234567'))->toBeTrue()
        ->and(PhilippinePhone::isMobile('0281234567'))->toBeFalse();
});

it('rejects invalid numbers', function (string $input) {
    expect(PhilippinePhone::isValid(PhilippinePhone::normalize($input)))->toBeFalse();
})->with(['12345', '0817123456789', 'abc', '+1 415 555 0100']);

it('returns null for empty input', function () {
    expect(PhilippinePhone::normalize(''))->toBeNull()
        ->and(PhilippinePhone::normalize(null))->toBeNull();
});
