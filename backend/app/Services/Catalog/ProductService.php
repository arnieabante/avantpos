<?php

namespace App\Services\Catalog;

use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Services\Media\MediaStorage;
use App\Support\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing products as one document (product + variants +
 * selling units + barcodes), in a single database transaction.
 *
 * Rules:
 *  - Plan: product limit; selling units need "unit_conversions"; expiry needs "batch_expiry".
 *  - SKUs and barcodes are unique in the business (deleted variants keep their SKU).
 *  - Changing the selling/wholesale price of an EXISTING variant needs prices.manage
 *    (an initial price on a new product/variant does not). Price changes are audited.
 *  - Variants and units with stock/sales/purchase history are never removed;
 *    type, base unit and inventory tracking are locked once history exists.
 */
class ProductService
{
    private const PRODUCT_FIELDS = [
        'category_id', 'brand_id', 'base_unit_id', 'name', 'description', 'type', 'tax_class',
        'sc_pwd_eligible', 'track_inventory', 'track_expiry', 'allow_negative_stock', 'meta', 'is_active',
    ];

    public function __construct(
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
        private readonly ProductHistory $history,
        private readonly MediaStorage $media,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated ProductRequest data
     */
    public function create(array $data): Product
    {
        $data = $this->normalise($data);
        $this->assertFeatures($data, null);

        return DB::transaction(function () use ($data) {
            $this->plan->assertCanAdd('products');
            $this->assertCodesAvailable($data['variants'], []);

            $product = Product::query()->create(Arr::only($data, self::PRODUCT_FIELDS) + ['is_active' => true]);

            $multiple = count($data['variants']) > 1;
            foreach (array_values($data['variants']) as $i => $variant) {
                $this->createVariant($product, $variant, $i === 0, $multiple);
            }

            $this->audit->log('product.created', $product, new: [
                'name' => $product->name,
                'variants' => count($data['variants']),
            ]);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated ProductRequest data
     */
    public function update(User $actor, Product $product, array $data): Product
    {
        $data = $this->normalise($data);
        $product->load(['variants.units', 'variants.barcodes']);
        /** @var Collection<int, ProductVariant> $existing */
        $existing = $product->variants->keyBy('id');
        $variants = array_values($data['variants']);

        // Variant ids must belong to this product.
        foreach ($variants as $i => $variant) {
            if (! empty($variant['id']) && ! $existing->has((int) $variant['id'])) {
                throw ValidationException::withMessages(["variants.{$i}.id" => 'This variant does not belong to the product.']);
            }
        }

        $this->assertFeatures($data, $product);

        $withHistory = $this->history->variantsWithHistory($existing->keys()->all());
        $this->assertStructureChangeAllowed($product, $data, $withHistory !== []);
        $this->assertPriceChangesAllowed($actor, $existing, $variants);

        $keptIds = collect($variants)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $removedIds = $existing->keys()->diff($keptIds)->values()->all();
        $blocked = array_intersect($removedIds, $withHistory);
        if ($blocked !== []) {
            $names = $existing->only($blocked)->map(fn (ProductVariant $v) => $v->name ?? $v->sku)->implode(', ');
            throw new DomainException(
                "These variants have stock or sales history and cannot be removed: {$names}. Deactivate them instead.",
                'VARIANT_HAS_HISTORY',
                422,
            );
        }

        return DB::transaction(function () use ($product, $data, $variants, $existing, $removedIds) {
            $this->assertCodesAvailable($variants, $existing->keys()->all());

            $product->fill(Arr::only($data, self::PRODUCT_FIELDS))->save();
            $this->audit->logChanges('product.updated', $product);

            // Barcodes are re-written from the document; clearing first lets a code move between variants.
            ProductBarcode::query()->whereIn('product_variant_id', $existing->keys())->delete();

            if ($removedIds !== []) {
                $this->removeVariants($product, $existing->only($removedIds));
            }

            $multiple = count($variants) > 1;
            foreach ($variants as $i => $variant) {
                if (! empty($variant['id'])) {
                    $this->updateVariant($existing[(int) $variant['id']], $variant, $multiple);
                } else {
                    $this->createVariant($product, $variant, false, $multiple);
                }
            }

            $this->ensureDefaultVariant($product);

            return $product->refresh();
        });
    }

    public function setActive(Product $product, bool $active): Product
    {
        if ($product->is_active !== $active) {
            $product->forceFill(['is_active' => $active])->save();
            $this->audit->log($active ? 'product.activated' : 'product.deactivated', $product);
        }

        return $product;
    }

    /**
     * Only products that were never stocked, bought or sold can be deleted;
     * anything else must be deactivated so history stays intact.
     */
    public function delete(Product $product): void
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id')->all();

        if ($this->history->variantsWithHistory($variantIds) !== []) {
            throw new DomainException('This product has stock, purchase or sales history. Deactivate it instead.', 'PRODUCT_HAS_HISTORY', 422);
        }

        DB::transaction(function () use ($product, $variantIds) {
            $this->audit->log('product.deleted', $product, old: ['name' => $product->name]);

            DB::table('inventory_balances')->whereIn('product_variant_id', $variantIds)->where('qty_on_hand', 0)->delete();
            // Units, barcodes and branch prices cascade with the variants.
            ProductVariant::withTrashed()->whereKey($variantIds)->forceDelete();
            $image = $product->image_path;
            $product->forceDelete();

            DB::afterCommit(fn () => $this->media->delete($image));
        });
    }

    /**
     * Replaces the branch-specific prices of a variant. Branches not listed
     * use the variant's own price.
     *
     * @param  list<array{branch_id: int, selling_price: string, wholesale_price?: ?string}>  $prices
     */
    public function setBranchPrices(ProductVariant $variant, array $prices): ProductVariant
    {
        return DB::transaction(function () use ($variant, $prices) {
            $before = $variant->branchPrices()->get(['branch_id', 'selling_price', 'wholesale_price'])
                ->map(fn ($p) => $p->only(['branch_id', 'selling_price', 'wholesale_price']))->all();

            $variant->branchPrices()->delete();
            foreach ($prices as $price) {
                $variant->branchPrices()->create([
                    'branch_id' => $price['branch_id'],
                    'selling_price' => $price['selling_price'],
                    'wholesale_price' => $price['wholesale_price'] ?? null,
                ]);
            }

            $after = $variant->branchPrices()->get()->map(fn ($p) => $p->only(['branch_id', 'selling_price', 'wholesale_price']))->all();
            $this->audit->log('product.branch_prices_changed', $variant, old: ['prices' => $before], new: ['prices' => $after],
                meta: ['product_id' => $variant->product_id, 'sku' => $variant->sku]);

            return $variant;
        });
    }

    public function replaceImage(Product $product, UploadedFile $file): Product
    {
        $previous = $product->image_path;
        $product->forceFill(['image_path' => $this->media->storeImage($file, 'products')])->save();
        $this->audit->log('product.image_updated', $product);
        $this->media->delete($previous);

        return $product;
    }

    public function removeImage(Product $product): Product
    {
        if ($product->image_path) {
            $this->media->delete($product->image_path);
            $product->forceFill(['image_path' => null])->save();
            $this->audit->log('product.image_removed', $product);
        }

        return $product;
    }

    /* ------------------------------------------------------------------ Internals */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (($data['type'] ?? 'standard') === 'service') {
            // Services have no stock.
            $data['track_inventory'] = false;
            $data['track_expiry'] = false;
            $data['allow_negative_stock'] = false;
        }

        if (array_key_exists('meta', $data)) {
            $meta = array_filter((array) $data['meta'], fn ($v) => $v !== null && $v !== '');
            $data['meta'] = $meta === [] ? null : $meta;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertFeatures(array $data, ?Product $product): void
    {
        if (! empty($data['track_expiry']) && ! $product?->track_expiry) {
            $this->plan->assertFeature('batch_expiry', 'Batch and expiry tracking');
        }

        $existingUnits = $product
            ? $product->variants->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->units->pluck('unit_id')->all()])->all()
            : [];

        foreach ($data['variants'] as $variant) {
            $known = $existingUnits[(int) ($variant['id'] ?? 0)] ?? [];
            foreach ($variant['units'] ?? [] as $unit) {
                if (! in_array((int) $unit['unit_id'], $known, true)) {
                    $this->plan->assertFeature('unit_conversions', 'Selling units (pack, box, meter…)');

                    return;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertStructureChangeAllowed(Product $product, array $data, bool $hasHistory): void
    {
        if (! $hasHistory) {
            return;
        }

        $changes = [
            'type' => ($data['type'] ?? $product->type) !== $product->type,
            'base unit' => (int) ($data['base_unit_id'] ?? $product->base_unit_id) !== $product->base_unit_id,
            'inventory tracking' => array_key_exists('track_inventory', $data) && (bool) $data['track_inventory'] !== $product->track_inventory,
            'expiry tracking' => array_key_exists('track_expiry', $data) && (bool) $data['track_expiry'] !== $product->track_expiry,
        ];

        $blocked = array_keys(array_filter($changes));
        if ($blocked !== []) {
            throw new DomainException(
                'This product already has stock or sales history, so its '.implode(', ', $blocked).' cannot be changed. Create a new product instead.',
                'PRODUCT_HAS_HISTORY',
                422,
            );
        }
    }

    /**
     * @param  Collection<int, ProductVariant>  $existing
     * @param  list<array<string, mixed>>  $variants
     */
    private function assertPriceChangesAllowed(User $actor, Collection $existing, array $variants): void
    {
        if ($actor->can('prices.manage')) {
            return;
        }

        foreach ($variants as $i => $variant) {
            if (empty($variant['id'])) {
                continue; // initial price of a new variant
            }

            $current = $existing[(int) $variant['id']];
            $changed = ! $this->samePrice($current->selling_price, $variant['selling_price'] ?? null)
                || ! $this->samePrice($current->wholesale_price, $variant['wholesale_price'] ?? null);

            $currentUnits = $current->units->keyBy('unit_id');
            foreach ($variant['units'] ?? [] as $unit) {
                $old = $currentUnits[(int) $unit['unit_id']] ?? null;
                if ($old && (! $this->samePrice($old->selling_price, $unit['selling_price'] ?? null)
                    || ! $this->samePrice($old->wholesale_price, $unit['wholesale_price'] ?? null))) {
                    $changed = true;
                }
            }

            if ($changed) {
                throw new DomainException('You do not have permission to change selling prices.', 'PRICE_CHANGE_FORBIDDEN', 403, ['variant_index' => $i]);
            }
        }
    }

    private function samePrice(?string $a, mixed $b): bool
    {
        if ($a === null || $b === null || $b === '') {
            return $a === null && ($b === null || $b === '');
        }

        return Money::of($a)->equals(Money::of((string) $b));
    }

    /**
     * SKUs (including those of deleted variants) and barcodes must be unique in the business.
     *
     * @param  list<array<string, mixed>>  $variants
     * @param  list<int>  $ownVariantIds  variants of the product being edited
     */
    private function assertCodesAvailable(array $variants, array $ownVariantIds): void
    {
        $errors = [];

        $skus = collect($variants)->map(fn ($v) => trim((string) ($v['sku'] ?? '')))->filter()->all();
        if ($skus !== []) {
            $taken = ProductVariant::withTrashed()->whereIn('sku', $skus)->whereKeyNot($ownVariantIds)->pluck('sku')
                ->map(fn ($s) => mb_strtoupper($s))->all();
            foreach ($skus as $i => $sku) {
                if (in_array(mb_strtoupper($sku), $taken, true)) {
                    $errors["variants.{$i}.sku"] = 'This SKU is already used by another product.';
                }
            }
        }

        $codes = [];
        foreach ($variants as $i => $variant) {
            foreach ($variant['barcodes'] ?? [] as $b => $code) {
                $codes["variants.{$i}.barcodes.{$b}"] = $code;
            }
            foreach ($variant['units'] ?? [] as $u => $unit) {
                foreach ($unit['barcodes'] ?? [] as $b => $code) {
                    $codes["variants.{$i}.units.{$u}.barcodes.{$b}"] = $code;
                }
            }
        }

        if ($codes !== []) {
            $taken = ProductBarcode::query()->whereIn('barcode', array_values($codes))
                ->whereNotIn('product_variant_id', $ownVariantIds ?: [0])
                ->pluck('barcode')->all();
            foreach ($codes as $key => $code) {
                if (in_array($code, $taken, true)) {
                    $errors[$key] = "Barcode {$code} is already used by another product.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createVariant(Product $product, array $data, bool $isDefault, bool $multiple): ProductVariant
    {
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => $multiple ? trim((string) $data['name']) : (($data['name'] ?? null) ?: null),
            'sku' => ($data['sku'] ?? '') !== '' ? $data['sku'] : $this->generateSku($product),
            'attributes' => $data['attributes'] ?? null,
            'cost_price' => $data['cost_price'] ?? '0',
            'selling_price' => $data['selling_price'],
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'reorder_level' => $data['reorder_level'] ?? '0',
            'is_default' => $isDefault,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->writeBarcodes($variant, null, $data['barcodes'] ?? []);

        foreach ($data['units'] ?? [] as $unit) {
            $this->writeUnit($variant, null, $unit);
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateVariant(ProductVariant $variant, array $data, bool $multiple): void
    {
        $old = $variant->only(['selling_price', 'wholesale_price']);

        $variant->fill([
            'name' => $multiple ? trim((string) $data['name']) : (($data['name'] ?? null) ?: null),
            'attributes' => $data['attributes'] ?? null,
            'selling_price' => $data['selling_price'],
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'reorder_level' => $data['reorder_level'] ?? '0',
            'is_active' => $data['is_active'] ?? $variant->is_active,
        ]);

        if (($data['sku'] ?? '') !== '') {
            $variant->sku = $data['sku'];
        }

        // Only users allowed to see costs can send them (validated); an omitted cost is kept.
        if (array_key_exists('cost_price', $data)) {
            $variant->cost_price = $data['cost_price'] ?? '0';
        }

        $variant->save();
        $this->audit->logChanges('product.variant_updated', $variant);

        if ($variant->wasChanged(['selling_price', 'wholesale_price'])) {
            $this->audit->log('product.price_changed', $variant,
                old: $old,
                new: $variant->only(['selling_price', 'wholesale_price']),
                meta: ['product_id' => $variant->product_id, 'sku' => $variant->sku],
            );
        }

        $this->writeBarcodes($variant, null, $data['barcodes'] ?? []);

        // Selling units: matched by unit_id.
        $current = $variant->units->keyBy('unit_id');
        $sentUnitIds = [];
        foreach ($data['units'] ?? [] as $unit) {
            $sentUnitIds[] = (int) $unit['unit_id'];
            $this->writeUnit($variant, $current[(int) $unit['unit_id']] ?? null, $unit);
        }

        // Note: Eloquent collections' only()/except() match MODEL KEYS, not keyBy() keys — filter explicitly.
        $removed = $current->reject(fn (ProductUnit $u) => in_array($u->unit_id, $sentUnitIds, true));
        if ($removed->isNotEmpty()) {
            if ($this->history->unitsWithHistory($removed->pluck('id')->all()) !== []) {
                throw new DomainException('A selling unit that was already bought or sold cannot be removed.', 'UNIT_HAS_HISTORY', 422);
            }
            ProductUnit::query()->whereKey($removed->pluck('id'))->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeUnit(ProductVariant $variant, ?ProductUnit $unit, array $data): void
    {
        $unit ??= new ProductUnit(['product_variant_id' => $variant->id]);
        $unit->fill([
            'unit_id' => $data['unit_id'],
            'conversion_factor' => $data['conversion_factor'],
            'selling_price' => $data['selling_price'] ?? null,
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'is_sellable' => $data['is_sellable'] ?? true,
            'is_purchasable' => $data['is_purchasable'] ?? true,
        ])->save();

        $this->writeBarcodes($variant, $unit, $data['barcodes'] ?? []);
    }

    /**
     * @param  list<string>  $codes
     */
    private function writeBarcodes(ProductVariant $variant, ?ProductUnit $unit, array $codes): void
    {
        foreach (array_unique($codes) as $code) {
            ProductBarcode::query()->create([
                'product_variant_id' => $variant->id,
                'product_unit_id' => $unit?->id,
                'barcode' => $code,
            ]);
        }
    }

    /**
     * Variants without history are deleted outright (freeing their SKU and barcodes).
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function removeVariants(Product $product, Collection $variants): void
    {
        $ids = $variants->pluck('id')->all();

        $this->audit->log('product.variants_removed', $product, old: [
            'variants' => $variants->map(fn (ProductVariant $v) => ['sku' => $v->sku, 'name' => $v->name])->values()->all(),
        ]);

        DB::table('inventory_balances')->whereIn('product_variant_id', $ids)->where('qty_on_hand', 0)->delete();
        ProductVariant::query()->whereKey($ids)->forceDelete();
    }

    private function ensureDefaultVariant(Product $product): void
    {
        $variants = $product->variants()->get();

        if ($variants->where('is_default', true)->count() === 1) {
            return;
        }

        $default = $variants->firstWhere('is_default', true) ?? $variants->first();
        ProductVariant::query()->where('product_id', $product->id)->update(['is_default' => false]);
        $default?->forceFill(['is_default' => true])->save();
    }

    /**
     * e.g. "CHI-7Q2K4F" for "Chippy BBQ". Checked against deleted variants too.
     */
    private function generateSku(Product $product): string
    {
        $prefix = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($product->name)) ?: 'PRD', 0, 3));

        do {
            $sku = $prefix.'-'.Str::upper(Str::random(6));
        } while (ProductVariant::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
