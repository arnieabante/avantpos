<?php

use App\Support\Money\Money;

it('keeps exactly two decimals and rounds half up', function () {
    expect(Money::of('10')->toString())->toBe('10.00')
        ->and(Money::of('10.005')->toString())->toBe('10.01')
        ->and(Money::of('10.004')->toString())->toBe('10.00')
        ->and(Money::of('-10.005')->toString())->toBe('-10.01');
});

it('does exact decimal arithmetic (no float drift)', function () {
    // 0.1 + 0.2 is 0.30000000000000004 in float arithmetic.
    expect(Money::of('0.10')->plus('0.20')->toString())->toBe('0.30')
        ->and(Money::sum(array_fill(0, 10, '0.10'))->toString())->toBe('1.00');
});

it('multiplies by fractional quantities', function () {
    // 1.25 kg at ₱89.90/kg = 112.375 -> 112.38
    expect(Money::of('89.90')->multipliedBy('1.25')->toString())->toBe('112.38');
});

it('extracts 12% VAT from a VAT-inclusive amount so parts add up exactly', function (string $gross, string $net, string $vat) {
    [$n, $v] = Money::of($gross)->extractTax('12');

    expect($n->toString())->toBe($net)
        ->and($v->toString())->toBe($vat)
        ->and($n->plus($v)->toString())->toBe(Money::of($gross)->toString());
})->with([
    ['112.00', '100.00', '12.00'],
    ['100.00', '89.29', '10.71'],
    ['1.00', '0.89', '0.11'],
    ['0.00', '0.00', '0.00'],
]);

it('computes the senior citizen / PWD discount on the VAT-exempt base', function () {
    // ₱112.00 VAT-inclusive -> ₱100.00 VAT-exempt base -> 20% = ₱20.00 -> pays ₱80.00
    [$base] = Money::of('112.00')->extractTax('12');
    $discount = $base->percentage('20');

    expect($discount->toString())->toBe('20.00')
        ->and($base->minus($discount)->toString())->toBe('80.00');
});

it('allocates without losing or creating a centavo', function () {
    $parts = Money::of('100.00')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->toString(), $parts))->toBe(['33.34', '33.33', '33.33'])
        ->and(Money::sum($parts)->toString())->toBe('100.00');
});

it('allocates a transaction discount pro-rata to line totals', function () {
    $parts = Money::of('10.00')->allocate(['a' => '59.90', 'b' => '120.00', 'c' => '0.10']);

    expect(Money::sum($parts)->toString())->toBe('10.00')
        ->and($parts['b']->greaterThan($parts['a']))->toBeTrue();
});

it('allocates negative amounts symmetrically', function () {
    $parts = Money::of('-0.05')->allocate([1, 1]);

    expect(Money::sum($parts)->toString())->toBe('-0.05');
});

it('rejects allocation across zero weights', function () {
    Money::of('1.00')->allocate([0, 0]);
})->throws(InvalidArgumentException::class);

it('converts to and from centavos', function () {
    expect(Money::ofCentavos(123456)->toString())->toBe('1234.56')
        ->and(Money::of('1234.56')->toCentavos())->toBe(123456);
});

it('formats pesos for display', function () {
    expect(Money::of('1234567.5')->format())->toBe('₱1,234,567.50')
        ->and(Money::of('-5')->format())->toBe('-₱5.00');
});

it('serializes to a decimal string in JSON', function () {
    expect(json_encode(['total' => Money::of('99.9')]))->toBe('{"total":"99.90"}');
});
