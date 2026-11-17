<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Receipt / invoice layout for 58mm, 80mm thermal printers or A4.
 */
#[Fillable(['name', 'paper', 'header_text', 'footer_text', 'show_logo', 'layout', 'is_default'])]
class ReceiptTemplate extends Model
{
    use BelongsToTenant;

    public const PAPERS = ['58mm', '80mm', 'a4'];

    /** Optional receipt sections the tenant can switch on/off (stored in `layout`). */
    public const LAYOUT_OPTIONS = [
        'show_business_address' => true,
        'show_tin' => true,
        'show_branch' => true,
        'show_cashier' => true,
        'show_customer' => true,
        'show_item_sku' => false,
        'show_tax_breakdown' => true,
        'show_payment_details' => true,
    ];

    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Layout with defaults filled in.
     *
     * @return array<string, bool>
     */
    public function resolvedLayout(): array
    {
        return array_merge(self::LAYOUT_OPTIONS, array_intersect_key($this->layout ?? [], self::LAYOUT_OPTIONS));
    }
}
