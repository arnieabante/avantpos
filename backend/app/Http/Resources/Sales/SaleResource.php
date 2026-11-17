<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\ApiResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\Sale;
use Illuminate\Http\Request;

/**
 * A sale with everything the receipt / invoice needs. SC/PWD ID numbers
 * are masked; cost of goods is shown only with inventory.valuation.
 *
 * @property Sale $resource
 */
class SaleResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $canCost = InventoryPresenter::canSeeCost($request);

        return [
            'id' => $s->id,
            'invoice_no' => $s->invoice_no,
            'status' => $s->status,
            'sold_at' => $s->sold_at?->toIso8601String(),
            'branch' => InventoryPresenter::branch($s->branch),
            'terminal' => $s->terminal ? [
                'id' => $s->terminal->id, 'code' => $s->terminal->code, 'name' => $s->terminal->name,
                // BIR machine details for the invoice (and its reprints).
                'min' => $s->terminal->min, 'serial_no' => $s->terminal->serial_no, 'ptu_no' => $s->terminal->ptu_no,
                'ptu_issued_at' => $s->terminal->ptu_issued_at?->toDateString(),
            ] : null,
            'cashier' => InventoryPresenter::user($s->cashier),
            'customer' => $s->customer ? ['id' => $s->customer->id, 'name' => $s->customer->name, 'tin' => $s->customer->tin, 'address' => $s->customer->address] : null,
            'totals' => [
                'gross' => $s->gross_amount,
                'line_discount' => $s->line_discount_total,
                'transaction_discount' => $s->transaction_discount_total,
                'sc_pwd_discount' => $s->sc_pwd_discount_total,
                'loyalty_discount' => $s->loyalty_discount,
                'vatable_sales' => $s->vatable_sales,
                'vat_amount' => $s->vat_amount,
                'vat_exempt_sales' => $s->vat_exempt_sales,
                'zero_rated_sales' => $s->zero_rated_sales,
                'total' => $s->total_amount,
                'amount_paid' => $s->amount_paid,
                'change' => $s->change_due,
            ] + ($canCost ? ['cogs' => $s->cogs_total] : []),
            'points' => ['redeemed' => $s->points_redeemed, 'earned' => $s->points_earned],
            'items' => $s->relationLoaded('items') ? $s->items->map(fn ($i) => [
                'id' => $i->id,
                'product_variant_id' => $i->product_variant_id,
                'name' => $i->variant_name ? "{$i->product_name} - {$i->variant_name}" : $i->product_name,
                'sku' => $i->sku,
                'unit' => $i->unit_label,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'line_discount' => $i->line_discount,
                'allocated_discount' => $i->allocated_transaction_discount,
                'sc_pwd_discount' => $i->sc_pwd_discount,
                'tax_class' => $i->tax_class,
                'vat_amount' => $i->vat_amount,
                'line_total' => $i->line_total,
                'qty_refunded' => $i->qty_refunded,
            ] + ($canCost ? ['cogs' => $i->cogs] : []))->all() : [],
            'discounts' => $s->relationLoaded('discounts') ? $s->discounts->map(fn ($d) => [
                'type' => $d->type,
                'scope' => $d->scope,
                'value_type' => $d->value_type,
                'value' => $d->value,
                'amount' => $d->amount,
                'sale_item_id' => $d->sale_item_id,
                'beneficiary' => $d->beneficiary_name ? [
                    'name' => $d->beneficiary_name,
                    'id_masked' => $d->beneficiary_id_no ? '••••'.mb_substr($d->beneficiary_id_no, -4) : null,
                    'tin' => $d->beneficiary_tin,
                ] : null,
                'authorized_by' => InventoryPresenter::user($d->authorizer),
            ])->all() : [],
            'payments' => $s->relationLoaded('payments') ? $s->payments->map(fn ($p) => [
                'method' => $p->method?->name,
                'type' => $p->method?->type,
                'amount' => $p->amount,
                'tendered' => $p->tendered,
                'change' => $p->change,
                'reference_no' => $p->reference_no,
            ])->all() : [],
            'notes' => $s->notes,
            'origin' => $s->origin,
            'sync_status' => $s->sync_status,
            'sync_issues' => $s->sync_issues,
            'void' => $s->status === 'voided' ? [
                'voided_at' => $s->voided_at?->toIso8601String(),
                'reason' => $s->void_reason,
                'by' => InventoryPresenter::user($s->relationLoaded('voider') ? $s->voider : null),
                'authorized_by' => InventoryPresenter::user($s->relationLoaded('voidAuthorizer') ? $s->voidAuthorizer : null),
            ] : null,
            'refunds' => $s->relationLoaded('refunds') ? RefundResource::collection($s->refunds)->toArray($request) : [],
            'can' => [
                'void' => $s->status === 'completed' && ($s->relationLoaded('session') && $s->session?->isOpen()),
                'refund' => $s->isRefundable(),
                'reprint' => (bool) $request->user()?->can('sales.reprint'),
            ],
        ];
    }
}
