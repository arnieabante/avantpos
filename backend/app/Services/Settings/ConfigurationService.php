<?php

namespace App\Services\Settings;

use App\Exceptions\DomainException;
use App\Models\DiscountPreset;
use App\Models\PaymentMethod;
use App\Models\ReceiptTemplate;
use App\Models\TaxRate;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for tenant configuration: tax rates, payment methods,
 * discount presets and receipt templates. Every change is audited.
 */
class ConfigurationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /* ---------------------------------------------------------------- Tax rates */

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTaxRate(array $data): TaxRate
    {
        return $this->created('tax_rate.created', TaxRate::query()->create($data + ['is_default' => false, 'is_active' => true]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTaxRate(TaxRate $rate, array $data): TaxRate
    {
        if ($rate->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new DomainException('The default tax rate cannot be deactivated. Make another rate the default first.', 'TAX_RATE_IS_DEFAULT', 422);
        }

        return $this->updated('tax_rate.updated', $rate, $data);
    }

    public function makeDefaultTaxRate(TaxRate $rate): TaxRate
    {
        if (! $rate->is_active) {
            throw new DomainException('Activate the tax rate before making it the default.', 'TAX_RATE_INACTIVE', 422);
        }

        return DB::transaction(function () use ($rate) {
            TaxRate::query()->whereKeyNot($rate->id)->where('is_default', true)->update(['is_default' => false]);
            $rate->forceFill(['is_default' => true])->save();
            $this->audit->log('tax_rate.default_changed', $rate);

            return $rate;
        });
    }

    /* ---------------------------------------------------------- Payment methods */

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPaymentMethod(array $data): PaymentMethod
    {
        $data['requires_reference'] = $this->requiresReference($data['type'], $data['requires_reference'] ?? null);
        $data['sort_order'] ??= (int) PaymentMethod::query()->max('sort_order') + 1;

        return $this->created('payment_method.created', PaymentMethod::query()->create($data + ['is_active' => true]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePaymentMethod(PaymentMethod $method, array $data): PaymentMethod
    {
        if (array_key_exists('requires_reference', $data)) {
            $data['requires_reference'] = $this->requiresReference($method->type, $data['requires_reference']);
        }

        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'] && $method->is_active;

        if ($deactivating && $method->type === 'cash'
            && ! PaymentMethod::query()->where('type', 'cash')->where('is_active', true)->whereKeyNot($method->id)->exists()) {
            throw new DomainException('At least one cash payment method must stay active.', 'LAST_CASH_METHOD', 422);
        }

        return $this->updated('payment_method.updated', $method, $data);
    }

    /**
     * Cash never needs a reference number; digital payments always do by default.
     */
    private function requiresReference(string $type, ?bool $requested): bool
    {
        if ($type === 'cash' || $type === 'credit') {
            return false;
        }

        return $requested ?? true;
    }

    /* ---------------------------------------------------------- Discount presets */

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDiscount(array $data): DiscountPreset
    {
        return $this->created('discount_preset.created', DiscountPreset::query()->create($data + ['is_active' => true, 'statutory' => null]));
    }

    /**
     * Statutory discounts (Senior Citizen RA 9994, PWD RA 10754, ...) keep the
     * rate, type and scope set by law and cannot be switched off.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDiscount(DiscountPreset $preset, array $data): DiscountPreset
    {
        if ($preset->isStatutory()) {
            $locked = ['type', 'value', 'scope', 'max_amount', 'is_active'];
            $preset->fill(Arr::only($data, $locked));

            if ($preset->isDirty($locked)) {
                throw new DomainException(
                    'This is a statutory discount required by law. Only its name and approval setting can be changed.',
                    'STATUTORY_DISCOUNT_LOCKED',
                    422,
                );
            }

            $data = Arr::only($data, ['name', 'requires_permission']);
        }

        return $this->updated('discount_preset.updated', $preset, $data);
    }

    /* --------------------------------------------------------- Receipt templates */

    /**
     * @param  array<string, mixed>  $data
     */
    public function createReceiptTemplate(array $data): ReceiptTemplate
    {
        $data['layout'] = $this->layout($data['layout'] ?? []);

        return $this->created('receipt_template.created', ReceiptTemplate::query()->create($data + ['is_default' => false]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateReceiptTemplate(ReceiptTemplate $template, array $data): ReceiptTemplate
    {
        if (array_key_exists('layout', $data)) {
            $data['layout'] = $this->layout($data['layout'] ?? []);
        }

        return $this->updated('receipt_template.updated', $template, $data);
    }

    public function makeDefaultReceiptTemplate(ReceiptTemplate $template): ReceiptTemplate
    {
        return DB::transaction(function () use ($template) {
            ReceiptTemplate::query()->whereKeyNot($template->id)->where('is_default', true)->update(['is_default' => false]);
            $template->forceFill(['is_default' => true])->save();
            $this->audit->log('receipt_template.default_changed', $template);

            return $template;
        });
    }

    public function deleteReceiptTemplate(ReceiptTemplate $template): void
    {
        if ($template->is_default) {
            throw new DomainException('The default receipt template cannot be deleted. Make another template the default first.', 'RECEIPT_TEMPLATE_IS_DEFAULT', 422);
        }

        $this->audit->log('receipt_template.deleted', $template, old: $template->only(['name', 'paper']));
        $template->delete();
    }

    /**
     * @param  array<string, bool>  $layout
     * @return array<string, bool>
     */
    private function layout(array $layout): array
    {
        return array_merge(ReceiptTemplate::LAYOUT_OPTIONS, array_map('boolval', Arr::only($layout, array_keys(ReceiptTemplate::LAYOUT_OPTIONS))));
    }

    /* ---------------------------------------------------------------- Helpers */

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private function created(string $event, Model $model): Model
    {
        $this->audit->log($event, $model, new: Arr::except($model->getAttributes(), ['tenant_id', 'created_at', 'updated_at']));

        return $model;
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $data
     * @return T
     */
    private function updated(string $event, Model $model, array $data): Model
    {
        $model->fill($data)->save();
        $this->audit->logChanges($event, $model);

        return $model;
    }
}
