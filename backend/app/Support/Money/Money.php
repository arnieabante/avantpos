<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable monetary amount in PHP pesos with exactly 2 decimal places.
 *
 * All arithmetic is arbitrary-precision (brick/math); NEVER use PHP floats
 * for money. Rounding policy: HALF_UP to the centavo, applied at the point
 * an amount is materialised (line totals, VAT per line).
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    public const ROUNDING = RoundingMode::HalfUp;

    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * @param  BigNumber|Money|int|string  $amount  Decimal string ("1234.50"), int pesos, or BigNumber. Floats are rejected.
     */
    public static function of(BigNumber|Money|int|string $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        return new self(BigDecimal::of($amount)->toScale(self::SCALE, self::ROUNDING));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public static function ofCentavos(int $centavos): self
    {
        return new self(BigDecimal::ofUnscaledValue($centavos, self::SCALE));
    }

    /**
     * @param  iterable<Money|string|int>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function plus(Money|string|int $other): self
    {
        return new self($this->amount->plus(self::of($other)->amount));
    }

    public function minus(Money|string|int $other): self
    {
        return new self($this->amount->minus(self::of($other)->amount));
    }

    /**
     * Multiply by a quantity / factor (decimal string or int) and round to centavos.
     */
    public function multipliedBy(BigNumber|string|int $factor): self
    {
        return new self($this->amount->multipliedBy($factor)->toScale(self::SCALE, self::ROUNDING));
    }

    public function dividedBy(BigNumber|string|int $divisor): self
    {
        return new self($this->amount->dividedBy($divisor, self::SCALE, self::ROUNDING));
    }

    /**
     * Percentage of this amount, e.g. ->percentage('20') for 20%.
     */
    public function percentage(BigNumber|string|int $percent): self
    {
        return new self(
            $this->amount->multipliedBy($percent)->dividedBy(100, self::SCALE, self::ROUNDING)
        );
    }

    /**
     * Split a VAT-INCLUSIVE amount into [net, vat] for the given rate (e.g. '12').
     * net = round(gross / (1 + rate/100)); vat = gross - net (so net + vat == gross exactly).
     *
     * @return array{0: Money, 1: Money}
     */
    public function extractTax(BigNumber|string|int $ratePercent): array
    {
        $divisor = BigDecimal::one()->plus(BigDecimal::of($ratePercent)->dividedBy(100, 6, self::ROUNDING));
        $net = new self($this->amount->dividedBy($divisor, self::SCALE, self::ROUNDING));

        return [$net, $this->minus($net)];
    }

    /**
     * Allocate this amount across weights using the largest-remainder method.
     * The parts always sum exactly to this amount (no lost/extra centavo).
     *
     * @param  array<array-key, BigNumber|Money|string|int>  $weights
     * @return array<array-key, Money>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $weights = array_map(
            fn ($w) => $w instanceof self ? $w->amount : BigDecimal::of($w),
            $weights
        );

        $totalWeight = array_reduce($weights, fn (BigDecimal $c, BigDecimal $w) => $c->plus($w), BigDecimal::zero());

        if ($totalWeight->isZero()) {
            throw new InvalidArgumentException('Cannot allocate across weights that sum to zero.');
        }

        $totalCentavos = $this->toCentavos();
        $sign = $totalCentavos < 0 ? -1 : 1;
        $absCentavos = abs($totalCentavos);

        $parts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $key => $weight) {
            $exact = BigDecimal::of($absCentavos)->multipliedBy($weight)->dividedBy($totalWeight, 10, RoundingMode::Down);
            $floor = $exact->toScale(0, RoundingMode::Down)->toInt();
            $parts[$key] = $floor;
            $remainders[$key] = $exact->minus($floor);
            $allocated += $floor;
        }

        // Hand out the leftover centavos to the largest remainders (stable order).
        $leftover = $absCentavos - $allocated;
        uasort($remainders, fn (BigDecimal $a, BigDecimal $b) => $b->compareTo($a));
        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }
            $parts[$key]++;
            $leftover--;
        }

        return array_map(fn (int $c) => self::ofCentavos($sign * $c), $parts);
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function compareTo(Money|string|int $other): int
    {
        return $this->amount->compareTo(self::of($other)->amount);
    }

    public function equals(Money|string|int $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(Money|string|int $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(Money|string|int $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function min(Money|string|int $other): self
    {
        return $this->lessThan($other) ? $this : self::of($other);
    }

    public function max(Money|string|int $other): self
    {
        return $this->greaterThan($other) ? $this : self::of($other);
    }

    public function toCentavos(): int
    {
        return $this->amount->getUnscaledValue()->toInt();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    /**
     * Decimal string with 2 places, e.g. "1234.50" — the DB / API format.
     */
    public function toString(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Display format, e.g. "₱1,234.50".
     */
    public function format(): string
    {
        $negative = $this->isNegative();
        [$int, $dec] = explode('.', (string) $this->amount->abs());

        return ($negative ? '-' : '').'₱'.number_format((int) $int).'.'.$dec;
    }
}
