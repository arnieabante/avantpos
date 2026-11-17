<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Input VAT on purchases.
 *
 * Supplier costs are entered as printed on the invoice (VAT-inclusive).
 * When the business AND the supplier are VAT-registered and the item is
 * VATable, the VAT inside the line is claimable input tax: it is split out
 * and inventory is valued net of it. Otherwise the full amount is cost.
 */
class PurchaseTax
{
    private ?BigDecimal $rate = null;

    private bool $resolved = false;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * VAT rate in percent when the business may claim input VAT, else null.
     */
    public function inputVatRate(): ?BigDecimal
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $tenant = $this->tenant->tenant();
            $rate = $tenant?->vat_registered
                ? TaxRate::query()->where('type', 'vat')->where('is_active', true)->orderByDesc('is_default')->value('rate')
                : null;
            $this->rate = $rate !== null ? BigDecimal::of($rate) : null;
        }

        return $this->rate;
    }

    /**
     * Line total (2 dp) and the claimable VAT inside it.
     *
     * @return array{total: BigDecimal, tax: BigDecimal}
     */
    public function line(string $qty, string $unitCost, Supplier $supplier, Product $product): array
    {
        $total = BigDecimal::of($qty)->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp);
        $rate = $this->inputVatRate();

        $claimable = $rate !== null && $rate->isPositive() && $supplier->vat_registered && $product->tax_class === 'vatable';
        $tax = $claimable
            ? $total->multipliedBy($rate)->dividedBy($rate->plus(100), 2, RoundingMode::HalfUp)
            : BigDecimal::zero()->toScale(2);

        return ['total' => $total, 'tax' => $tax];
    }
}
