<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a product as one document:
 *
 *   { name, category_id, base_unit_id, ..., variants: [
 *       { id?, name, sku, cost_price, selling_price, wholesale_price, reorder_level, is_active,
 *         barcodes: [..], units: [ { unit_id, conversion_factor, selling_price, ..., barcodes: [..] } ] } ] }
 *
 * Shape and formats are checked here; rules that need the database (SKU and
 * barcode uniqueness, price permissions, history) are checked by ProductService.
 */
class ProductRequest extends FormRequest
{
    private const MONEY = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'];

    private const CODE = 'regex:/^[A-Za-z0-9._\-\/]+$/';

    protected function prepareForValidation(): void
    {
        // Trim codes so a scanner's trailing whitespace does not create "different" barcodes.
        $variants = $this->input('variants');
        if (is_array($variants)) {
            foreach ($variants as $i => $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                if (isset($variant['sku']) && is_string($variant['sku'])) {
                    $variants[$i]['sku'] = trim($variant['sku']);
                }
                $variants[$i]['barcodes'] = $this->trimCodes($variant['barcodes'] ?? []);
                foreach ((array) ($variant['units'] ?? []) as $u => $unit) {
                    if (is_array($unit)) {
                        $variants[$i]['units'][$u]['barcodes'] = $this->trimCodes($unit['barcodes'] ?? []);
                    }
                }
            }
            $this->merge(['variants' => $variants]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $canSeeCost = (bool) $this->user()?->can('products.view_cost');

        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', TenantExists::on('product_categories')],
            'brand_id' => ['nullable', 'integer', TenantExists::on('brands')],
            'base_unit_id' => ['required', 'integer', TenantExists::on('units')],
            'type' => ['required', Rule::in(Product::TYPES)],
            'tax_class' => ['required', Rule::in(Product::TAX_CLASSES)],
            'sc_pwd_eligible' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array:'.implode(',', array_keys(Product::META_FIELDS))],
            'meta.generic_name' => ['nullable', 'string', 'max:150'],
            'meta.requires_prescription' => ['nullable', 'boolean'],

            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['sometimes', 'nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:60', self::CODE],
            'variants.*.attributes' => ['nullable', 'array', 'max:5'],
            'variants.*.attributes.*' => ['nullable', 'string', 'max:60'],
            // Cost is confidential: users without products.view_cost may not send it.
            'variants.*.cost_price' => $canSeeCost ? ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999'] : ['prohibited'],
            'variants.*.selling_price' => ['required', ...self::MONEY],
            'variants.*.wholesale_price' => ['nullable', ...self::MONEY],
            'variants.*.reorder_level' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999999'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.barcodes' => ['array', 'max:10'],
            'variants.*.barcodes.*' => ['string', 'min:3', 'max:64', self::CODE],

            'variants.*.units' => ['array', 'max:10'],
            'variants.*.units.*.unit_id' => ['required', 'integer', TenantExists::on('units')],
            'variants.*.units.*.conversion_factor' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'variants.*.units.*.selling_price' => ['nullable', ...self::MONEY],
            'variants.*.units.*.wholesale_price' => ['nullable', ...self::MONEY],
            'variants.*.units.*.is_sellable' => ['sometimes', 'boolean'],
            'variants.*.units.*.is_purchasable' => ['sometimes', 'boolean'],
            'variants.*.units.*.barcodes' => ['array', 'max:5'],
            'variants.*.units.*.barcodes.*' => ['string', 'min:3', 'max:64', self::CODE],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $variants = (array) $this->input('variants', []);
            $baseUnitId = (int) $this->input('base_unit_id');

            // With several variants each needs a distinct name (e.g. "Red / XL").
            if (count($variants) > 1) {
                $seen = [];
                foreach ($variants as $i => $variant) {
                    $name = mb_strtolower(trim((string) ($variant['name'] ?? '')));
                    if ($name === '') {
                        $validator->errors()->add("variants.{$i}.name", 'Name each variant, e.g. "Small" or "Red / XL".');
                    } elseif (isset($seen[$name])) {
                        $validator->errors()->add("variants.{$i}.name", 'Two variants have the same name.');
                    }
                    $seen[$name] = true;
                }
            }

            $skus = [];
            $barcodes = [];
            foreach ($variants as $i => $variant) {
                $sku = mb_strtoupper((string) ($variant['sku'] ?? ''));
                if ($sku !== '') {
                    if (isset($skus[$sku])) {
                        $validator->errors()->add("variants.{$i}.sku", 'Two variants have the same SKU.');
                    }
                    $skus[$sku] = true;
                }

                foreach ((array) ($variant['barcodes'] ?? []) as $b => $code) {
                    $this->checkDuplicateBarcode($validator, $barcodes, (string) $code, "variants.{$i}.barcodes.{$b}");
                }

                $unitIds = [];
                foreach ((array) ($variant['units'] ?? []) as $u => $unit) {
                    $unitId = (int) $unit['unit_id'];
                    if ($unitId === $baseUnitId) {
                        $validator->errors()->add("variants.{$i}.units.{$u}.unit_id", 'This is already the base unit.');
                    } elseif (isset($unitIds[$unitId])) {
                        $validator->errors()->add("variants.{$i}.units.{$u}.unit_id", 'This unit is listed twice.');
                    }
                    $unitIds[$unitId] = true;

                    foreach ((array) ($unit['barcodes'] ?? []) as $b => $code) {
                        $this->checkDuplicateBarcode($validator, $barcodes, (string) $code, "variants.{$i}.units.{$u}.barcodes.{$b}");
                    }
                }
            }

            if ($this->input('type') === 'service' && collect($variants)->contains(fn ($v) => ! empty($v['units']))) {
                $validator->errors()->add('type', 'Services cannot have selling units.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variants.*.sku.regex' => 'Use letters, numbers, dashes, dots or slashes only.',
            'variants.*.barcodes.*.regex' => 'A barcode may contain letters, numbers, dashes, dots or slashes only.',
            'variants.*.units.*.barcodes.*.regex' => 'A barcode may contain letters, numbers, dashes, dots or slashes only.',
            'variants.*.cost_price.prohibited' => 'You do not have permission to set cost prices.',
            'variants.*.selling_price.required' => 'Enter the selling price.',
            'variants.*.units.*.conversion_factor.gt' => 'The quantity per unit must be more than zero.',
            'meta' => 'Unknown extra product field.',
        ];
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function checkDuplicateBarcode(Validator $validator, array &$seen, string $code, string $key): void
    {
        if (isset($seen[$code])) {
            $validator->errors()->add($key, 'This barcode is entered more than once.');
        }
        $seen[$code] = true;
    }

    /**
     * @return list<string>
     */
    private function trimCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($c) => is_string($c) ? trim($c) : $c, $codes), fn ($c) => $c !== ''));
    }
}
