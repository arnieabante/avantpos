<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\DiscountPreset;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Services\Billing\PlanGate;
use App\Services\Catalog\PriceResolver;
use App\Services\Customers\LoyaltyService;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Prices a cart. The ONLY place sale amounts are computed: the POS sends
 * items, quantities and requested discounts; every price, discount, tax and
 * total comes from here (never from the client).
 *
 * Philippine rules applied:
 *  - Prices are VAT-inclusive. For a VAT-registered business, VATable lines
 *    carry VAT = amount × rate / (100 + rate); VAT-exempt and zero-rated
 *    lines carry none. A non-VAT business records no VAT (non-VAT sales).
 *  - Senior Citizen (RA 9994) / PWD (RA 10754): on eligible items the 20%
 *    discount is computed on the price net of VAT, and the line is VAT-exempt.
 *    It is not combined with any other discount on that line, and SC/PWD
 *    lines are excluded from the sale-wide discount and points redemption.
 *  - Order: line discount → sale-wide discount (spread over the non-SC/PWD
 *    lines by their amount) → redeemed loyalty points (a sales discount, so it
 *    also lowers the VAT base) → VAT split per line.
 *
 * Every amount is rounded to centavos at the step it is computed (Money).
 * The result lists the permissions the cart needs ("approvals"); the caller
 * decides whether the cashier has them or a supervisor approved.
 */
class SaleCalculator
{
    private ?BigDecimal $vatRate = null;

    private bool $vatResolved = false;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PriceResolver $prices,
        private readonly PlanGate $plan,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * @param  array<string, mixed>  $cart  validated cart (see SaleRequest)
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, customer: Customer|null,
     *               discounts: list<array<string, mixed>>, approvals: list<string>, sc_pwd: array<string, mixed>|null,
     *               points_redeemed: string, vat_registered: bool}
     */
    public function calculate(int $branchId, array $cart): array
    {
        $approvals = [];
        $discounts = [];
        $customer = isset($cart['customer_id']) ? $this->customer((int) $cart['customer_id']) : null;
        $wholesale = $customer?->group?->price_tier === 'wholesale';
        $maxPercent = BigDecimal::of(config('avantpos.app.pos.max_discount_percent', '20'));
        $scRate = BigDecimal::of(config('avantpos.app.pos.sc_pwd_percent', '20'));
        $vatRate = $this->vatRate();
        $scPwd = $cart['sc_pwd'] ?? null;

        $variants = ProductVariant::query()->with(['product.baseUnit', 'product.category'])
            ->whereIn('id', array_column($cart['items'], 'product_variant_id'))->get()->keyBy('id');
        $this->prices->preload($branchId, $variants->keys());

        /* ---------------------------------------------------------- 1. lines */
        $lines = [];
        foreach (array_values($cart['items']) as $i => $item) {
            $variant = $variants[$item['product_variant_id']] ?? null;
            $field = "items.{$i}";
            // An offline sale already happened: an item deactivated since is still recorded.
            if (! $variant || (empty($cart['_offline']) && (! $variant->is_active || ! $variant->product->is_active))) {
                throw ValidationException::withMessages(["{$field}.product_variant_id" => 'This item is not available for sale.']);
            }
            $product = $variant->product;
            $unit = $this->sellingUnit($variant, $item['product_unit_id'] ?? null, $field);
            $factor = $unit ? BigDecimal::of($unit->conversion_factor) : BigDecimal::one();

            $qty = BigDecimal::of($item['qty']);
            $wholeOnly = $unit !== null || ! ($product->baseUnit?->allows_decimal ?? false);
            if (! $qty->isPositive() || ($wholeOnly && ! $qty->getFractionalPart()->isZero())) {
                throw ValidationException::withMessages(["{$field}.qty" => $wholeOnly ? 'Enter a whole quantity.' : 'Enter a quantity.']);
            }

            $price = $this->prices->resolve($variant, $branchId, $unit);
            $listPrice = Money::of($wholesale && $price['wholesale_price'] !== null ? $price['wholesale_price'] : $price['selling_price']);
            $unitPrice = $listPrice;
            $overridden = false;
            if (isset($item['unit_price']) && ! Money::of($item['unit_price'])->equals($listPrice)) {
                $unitPrice = Money::of($item['unit_price']);
                $overridden = true;
                $approvals[] = 'pos.price_override';
            }

            $gross = $unitPrice->multipliedBy($qty);
            $line = [
                'index' => $i,
                'variant' => $variant,
                'product' => $product,
                'unit' => $unit,
                'unit_label' => $unit?->unit?->abbreviation ?? $product->baseUnit?->abbreviation,
                'factor' => $factor,
                'qty' => $qty,
                'base_qty' => $qty->multipliedBy($factor)->toScale(3, RoundingMode::HalfUp),
                'list_price' => $listPrice,
                'unit_price' => $unitPrice,
                'price_overridden' => $overridden,
                'gross' => $gross,
                'line_discount' => Money::zero(),
                'line_discount_info' => null,
                'sc_pwd' => (bool) ($item['sc_pwd'] ?? false),
                'sc_pwd_discount' => Money::zero(),
                'allocated_transaction_discount' => Money::zero(),
                'allocated_loyalty' => Money::zero(),
            ];

            if ($line['sc_pwd']) {
                if (! $scPwd) {
                    throw ValidationException::withMessages(['sc_pwd' => 'Enter the Senior Citizen / PWD details.']);
                }
                if (! $product->sc_pwd_eligible) {
                    throw ValidationException::withMessages(["{$field}.sc_pwd" => "{$product->name} is not eligible for the Senior Citizen / PWD discount."]);
                }
                if (! empty($item['discount'])) {
                    throw ValidationException::withMessages(["{$field}.discount" => 'The Senior Citizen / PWD discount cannot be combined with another discount.']);
                }
                $approvals[] = 'pos.sc_pwd';
            } elseif (! empty($item['discount'])) {
                [$amount, $info] = $this->discount($item['discount'], $gross, 'line', "{$field}.discount");
                $line['line_discount'] = $amount;
                $line['line_discount_info'] = $info;
                $approvals[] = 'pos.discount_line';
                if ($info['requires_permission'] || $this->exceeds($amount, $gross, $maxPercent)) {
                    $approvals[] = 'pos.discount_override_limit';
                }
            }

            $lines[] = $line;
        }

        if ($scPwd && ! collect($lines)->contains('sc_pwd', true)) {
            throw ValidationException::withMessages(['sc_pwd' => 'Mark the items the Senior Citizen / PWD discount applies to.']);
        }

        /* ---------------------------------------------------------- 2. sale-wide discount */
        $regular = array_keys(array_filter($lines, fn ($l) => ! $l['sc_pwd']));
        $netOf = fn (array $l) => $l['gross']->minus($l['line_discount']);
        $base = Money::sum(array_map(fn ($k) => $netOf($lines[$k]), $regular));

        $transaction = $cart['discount'] ?? null;
        $transactionInfo = null;
        $transactionAmount = Money::zero();
        if ($transaction) {
            if ($regular === []) {
                throw ValidationException::withMessages(['discount' => 'There are no items the sale discount can apply to.']);
            }
            [$transactionAmount, $transactionInfo] = $this->discount($transaction, $base, 'transaction', 'discount');
            $approvals[] = 'pos.discount_transaction';
            if ($transactionInfo['requires_permission'] || $this->exceeds($transactionAmount, $base, $maxPercent)) {
                $approvals[] = 'pos.discount_override_limit';
            }
        } elseif ($customer?->group && $regular !== [] && BigDecimal::of($customer->group->discount_percent)->isPositive()) {
            // The customer's group discount applies when no other sale discount is given.
            $transactionAmount = $base->percentage($customer->group->discount_percent);
            $transactionInfo = ['type' => 'customer_group', 'value_type' => 'percent', 'value' => (string) $customer->group->discount_percent,
                'preset_id' => null, 'name' => $customer->group->name, 'requires_permission' => false];
        }
        $this->spread($lines, $regular, $transactionAmount, 'allocated_transaction_discount', $netOf);

        /* ---------------------------------------------------------- 3. points */
        $pointsRedeemed = BigDecimal::zero();
        $loyaltyAmount = Money::zero();
        if (! empty($cart['redeem_points'])) {
            [$pointsRedeemed, $loyaltyAmount] = $this->redemption($customer, (string) $cart['redeem_points'],
                $base->minus($transactionAmount), $regular !== []);
            $this->spread($lines, $regular, $loyaltyAmount, 'allocated_loyalty',
                fn ($l) => $netOf($l)->minus($l['allocated_transaction_discount']));
        }

        /* ---------------------------------------------------------- 4. VAT and totals */
        $totals = array_fill_keys(['gross', 'line_discount', 'transaction_discount', 'sc_pwd_discount', 'loyalty_discount',
            'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'zero_rated_sales', 'total'], Money::zero());

        foreach ($lines as &$l) {
            $taxClass = $l['product']->tax_class;
            $vatable = $vatRate !== null && $taxClass === 'vatable';

            if ($l['sc_pwd']) {
                // 20% of the price net of VAT; the line is VAT-exempt.
                $exempt = $vatable ? $l['gross']->extractTax($vatRate)[0] : $l['gross'];
                $l['sc_pwd_discount'] = $exempt->percentage($scRate);
                $l['line_total'] = $exempt->minus($l['sc_pwd_discount']);
                $l['vat_amount'] = Money::zero();
                $l['tax_class'] = 'vat_exempt';
                $totals['vat_exempt_sales'] = $totals['vat_exempt_sales']->plus($exempt);
            } else {
                $amount = $netOf($l)->minus($l['allocated_transaction_discount'])->minus($l['allocated_loyalty']);
                $l['line_total'] = $amount;
                if ($vatable) {
                    [$net, $vat] = $amount->extractTax($vatRate);
                    $l['vat_amount'] = $vat;
                    $l['tax_class'] = 'vatable';
                    $totals['vatable_sales'] = $totals['vatable_sales']->plus($net);
                    $totals['vat_amount'] = $totals['vat_amount']->plus($vat);
                } elseif ($vatRate !== null && $taxClass === 'zero_rated') {
                    $l['vat_amount'] = Money::zero();
                    $l['tax_class'] = 'zero_rated';
                    $totals['zero_rated_sales'] = $totals['zero_rated_sales']->plus($amount);
                } else {
                    // VAT-exempt item, or a non-VAT business (recorded as non-VAT sales).
                    $l['vat_amount'] = Money::zero();
                    $l['tax_class'] = $vatRate === null ? 'non_vat' : 'vat_exempt';
                    $totals['vat_exempt_sales'] = $totals['vat_exempt_sales']->plus($amount);
                }
            }

            $totals['gross'] = $totals['gross']->plus($l['gross']);
            $totals['line_discount'] = $totals['line_discount']->plus($l['line_discount']);
            $totals['sc_pwd_discount'] = $totals['sc_pwd_discount']->plus($l['sc_pwd_discount']);
            $totals['total'] = $totals['total']->plus($l['line_total']);
        }
        unset($l);
        $totals['transaction_discount'] = $transactionAmount;
        $totals['loyalty_discount'] = $loyaltyAmount;

        /* ---------------------------------------------------------- discount records */
        foreach ($lines as $l) {
            if ($l['line_discount_info']) {
                $discounts[] = $l['line_discount_info'] + ['line' => $l['index'], 'scope' => 'line', 'amount' => $l['line_discount']];
            }
            if ($l['sc_pwd']) {
                $discounts[] = ['line' => $l['index'], 'scope' => 'line', 'type' => $scPwd['type'], 'value_type' => 'percent',
                    'value' => (string) $scRate, 'preset_id' => null, 'name' => $scPwd['type'] === 'senior' ? 'Senior Citizen' : 'PWD',
                    'amount' => $l['sc_pwd_discount'], 'requires_permission' => false];
            }
        }
        if ($transactionInfo && $transactionAmount->isPositive()) {
            $discounts[] = $transactionInfo + ['line' => null, 'scope' => 'transaction', 'amount' => $transactionAmount];
        }
        if ($loyaltyAmount->isPositive()) {
            $discounts[] = ['line' => null, 'scope' => 'transaction', 'type' => 'loyalty', 'value_type' => 'fixed',
                'value' => (string) $loyaltyAmount, 'preset_id' => null, 'name' => "{$pointsRedeemed} points", 'amount' => $loyaltyAmount,
                'requires_permission' => false];
        }

        return [
            'lines' => $lines,
            'totals' => array_map(fn (Money $m) => (string) $m, $totals),
            'customer' => $customer,
            'discounts' => $discounts,
            'approvals' => array_values(array_unique($approvals)),
            'sc_pwd' => $scPwd,
            'points_redeemed' => (string) $pointsRedeemed->toScale(2),
            'vat_registered' => $vatRate !== null,
        ];
    }

    /**
     * Discount amount for a request: a preset (its own type/value/cap), or a
     * manual percent / fixed amount. Never more than the amount it applies to.
     *
     * @param  array{type?: string, value?: string|int|float, preset_id?: int|null}  $request
     * @return array{0: Money, 1: array<string, mixed>}
     */
    private function discount(array $request, Money $base, string $scope, string $field): array
    {
        if (! empty($request['preset_id'])) {
            $preset = DiscountPreset::query()->where('is_active', true)->find($request['preset_id']);
            if (! $preset || $preset->scope !== $scope || $preset->statutory !== null) {
                throw ValidationException::withMessages(["{$field}.preset_id" => $preset?->statutory
                    ? 'Use the Senior Citizen / PWD option for statutory discounts.'
                    : 'This discount cannot be used here.']);
            }
            $type = $preset->type;
            $value = (string) $preset->value;
            $info = ['type' => 'preset', 'preset_id' => $preset->getKey(), 'name' => $preset->name, 'requires_permission' => $preset->requires_permission];
        } else {
            $type = $request['type'] ?? null;
            $value = (string) ($request['value'] ?? '');
            $info = ['type' => 'manual', 'preset_id' => null, 'name' => 'Discount', 'requires_permission' => false];
            $preset = null;
        }

        if (! in_array($type, DiscountPreset::TYPES, true) || ! is_numeric($value) || BigDecimal::of($value)->isNegative()) {
            throw ValidationException::withMessages(["{$field}.value" => 'Enter a valid discount.']);
        }
        if ($type === 'percent' && BigDecimal::of($value)->isGreaterThan(100)) {
            throw ValidationException::withMessages(["{$field}.value" => 'A discount cannot be more than 100%.']);
        }

        $amount = $type === 'percent' ? $base->percentage($value) : Money::of($value);
        if ($preset?->max_amount !== null) {
            $amount = $amount->min($preset->max_amount);
        }
        if ($amount->greaterThan($base)) {
            throw ValidationException::withMessages(["{$field}.value" => "The discount cannot be more than {$base->format()}."]);
        }

        return [$amount, $info + ['value_type' => $type, 'value' => $value]];
    }

    private function exceeds(Money $discount, Money $base, BigDecimal $maxPercent): bool
    {
        return $base->isPositive() && $discount->greaterThan($base->percentage($maxPercent));
    }

    /**
     * Spread an amount over the given lines in proportion to their amounts
     * (largest remainder: the parts always add up exactly).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<int>  $keys
     */
    private function spread(array &$lines, array $keys, Money $amount, string $column, callable $weightOf): void
    {
        if (! $amount->isPositive() || $keys === []) {
            return;
        }
        $weights = [];
        foreach ($keys as $k) {
            $weights[$k] = $weightOf($lines[$k]);
        }
        if (Money::sum($weights)->isZero()) {
            return;
        }
        foreach ($amount->allocate($weights) as $k => $part) {
            $lines[$k][$column] = $part;
        }
    }

    /**
     * @return array{0: BigDecimal, 1: Money}
     */
    private function redemption(?Customer $customer, string $points, Money $available, bool $hasRegularLines): array
    {
        if (! $this->plan->hasFeature('loyalty') || ! $this->loyalty->settings()['enabled']) {
            throw ValidationException::withMessages(['redeem_points' => 'Loyalty points are not enabled.']);
        }
        if (! $customer) {
            throw ValidationException::withMessages(['redeem_points' => 'Choose the customer who is using points.']);
        }
        if (! $hasRegularLines) {
            throw ValidationException::withMessages(['redeem_points' => 'Points cannot be used on Senior Citizen / PWD items.']);
        }

        $settings = $this->loyalty->settings();
        $wanted = BigDecimal::of($points);
        if (! $wanted->isPositive() || ! $wanted->getFractionalPart()->isZero()) {
            throw ValidationException::withMessages(['redeem_points' => 'Enter whole points.']);
        }
        if ($wanted->isGreaterThan($customer->loyalty_points)) {
            throw ValidationException::withMessages(['redeem_points' => "{$customer->name} has only ".BigDecimal::of($customer->loyalty_points)->toScale(0, RoundingMode::Down).' points.']);
        }
        if ($wanted->isLessThan($settings['min_redeem_points'])) {
            throw ValidationException::withMessages(['redeem_points' => "At least {$settings['min_redeem_points']} points must be used at a time."]);
        }

        $value = Money::of($settings['point_value'])->multipliedBy($wanted);
        if ($value->greaterThan($available)) {
            throw ValidationException::withMessages(['redeem_points' => "Points worth more than {$available->format()} cannot be used on this sale."]);
        }

        return [$wanted, $value];
    }

    private function sellingUnit(ProductVariant $variant, ?int $productUnitId, string $field): ?ProductUnit
    {
        if ($productUnitId === null) {
            return null;
        }
        $unit = ProductUnit::query()->with('unit')->where('product_variant_id', $variant->getKey())->find($productUnitId);
        if (! $unit || ! $unit->is_sellable) {
            throw ValidationException::withMessages(["{$field}.product_unit_id" => 'This item cannot be sold in that unit.']);
        }

        return $unit;
    }

    private function customer(int $id): Customer
    {
        $customer = Customer::query()->with('group')->find($id);
        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => 'Choose an active customer.']);
        }

        return $customer;
    }

    /**
     * VAT rate in percent for a VAT-registered business, else null.
     */
    /** VAT rate (e.g. 12) when the business is VAT-registered, else null. */
    public function vatRate(): ?BigDecimal
    {
        if (! $this->vatResolved) {
            $this->vatResolved = true;
            $rate = $this->tenant->tenant()?->vat_registered
                ? TaxRate::query()->where('type', 'vat')->where('is_active', true)->orderByDesc('is_default')->value('rate')
                : null;
            $this->vatRate = $rate !== null ? BigDecimal::of($rate) : null;
        }

        return $this->vatRate;
    }
}
