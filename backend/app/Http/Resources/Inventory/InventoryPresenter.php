<?php

namespace App\Http\Resources\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Small shapes shared by the inventory resources.
 */
final class InventoryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function variant(ProductVariant $variant): array
    {
        $product = $variant->product;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->name ? "{$product->name} - {$variant->name}" : $product->name,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'unit' => $product->baseUnit?->abbreviation,
            'allows_decimal' => (bool) ($product->baseUnit?->allows_decimal ?? false),
            'track_expiry' => (bool) $product->track_expiry,
            'is_active' => $variant->is_active && $product->is_active,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public static function branch(?Branch $branch): ?array
    {
        return $branch ? ['id' => $branch->id, 'name' => $branch->name] : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public static function user(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    /** Inventory costs and values are shown only with inventory.valuation. */
    public static function canSeeCost(Request $request): bool
    {
        return (bool) $request->user()?->can('inventory.valuation');
    }
}
