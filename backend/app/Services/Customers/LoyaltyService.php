<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty points: program settings and manual adjustments. Earning and
 * redeeming at checkout come with the POS (Phase 5) through the same ledger.
 */
class LoyaltyService
{
    public const SETTINGS_KEY = 'loyalty';

    public const DEFAULTS = [
        'enabled' => false,
        'spend_per_point' => '100.00', // ₱ spent to earn 1 point
        'point_value' => '1.00',        // ₱ value of 1 point when redeemed
        'min_redeem_points' => 0,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlanGate $plan,
    ) {}

    /**
     * @return array{enabled: bool, spend_per_point: string, point_value: string, min_redeem_points: int}
     */
    public function settings(): array
    {
        $stored = TenantSetting::query()->where('key', self::SETTINGS_KEY)->value('value') ?? [];

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /**
     * @param  array{enabled: bool, spend_per_point: string, point_value: string, min_redeem_points: int}  $settings
     * @return array{enabled: bool, spend_per_point: string, point_value: string, min_redeem_points: int}
     */
    public function saveSettings(array $settings): array
    {
        $this->plan->assertFeature('loyalty', 'Loyalty points');
        $current = $this->settings();
        $next = [
            'enabled' => (bool) $settings['enabled'],
            'spend_per_point' => (string) BigDecimal::of($settings['spend_per_point'])->toScale(2),
            'point_value' => (string) BigDecimal::of($settings['point_value'])->toScale(2),
            'min_redeem_points' => (int) $settings['min_redeem_points'],
        ];

        if ($next !== $current) {
            TenantSetting::query()->updateOrCreate(['key' => self::SETTINGS_KEY], ['value' => $next]);
            $this->audit->log('loyalty.settings_updated', old: $current, new: $next);
        }

        return $next;
    }

    /**
     * Points earned on a sale: whole points per "spend per point" pesos.
     * Returns 0 when the programme is off. Called inside the checkout transaction.
     */
    public function earn(Customer $customer, string $amountSpent, int $saleId, User $actor): BigDecimal
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || ! $this->plan->hasFeature('loyalty')) {
            return BigDecimal::zero();
        }
        $points = BigDecimal::of($amountSpent)->dividedBy($settings['spend_per_point'], 0, RoundingMode::Down);
        if (! $points->isPositive()) {
            return BigDecimal::zero();
        }

        $this->record($customer, $points, 'earn', null, $saleId, $actor);

        return $points;
    }

    /**
     * Points used as a discount on a sale (validated by the SaleCalculator).
     */
    public function redeem(Customer $customer, BigDecimal $points, int $saleId, User $actor): void
    {
        $this->record($customer, $points->negated(), 'redeem', null, $saleId, $actor);
    }

    /**
     * Points moved back because a sale was voided or refunded. A deduction is
     * limited to what the customer still has (points may already be spent).
     * Returns the change actually recorded.
     */
    public function reverse(Customer $customer, BigDecimal $delta, int $saleId, User $actor, string $notes): BigDecimal
    {
        if ($delta->isZero()) {
            return $delta;
        }
        if ($delta->isNegative()) {
            $have = BigDecimal::of(Customer::query()->whereKey($customer->getKey())->value('loyalty_points') ?? '0');
            $delta = BigDecimal::max($delta, $have->negated());
            if ($delta->isZero()) {
                return $delta;
            }
        }
        $this->record($customer, $delta, 'refund', $notes, $saleId, $actor);

        return $delta;
    }

    private function record(Customer $customer, BigDecimal $delta, string $reason, ?string $notes, ?int $saleId, User $actor): LoyaltyTransaction
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
        $after = BigDecimal::of($customer->loyalty_points)->plus($delta);
        if ($after->isNegative()) {
            throw ValidationException::withMessages(['redeem_points' => "{$customer->name} does not have enough points."]);
        }

        $entry = new LoyaltyTransaction;
        $entry->forceFill([
            'customer_id' => $customer->getKey(),
            'points' => (string) $delta->toScale(2),
            'balance_after' => (string) $after->toScale(2),
            'reason' => $reason,
            'notes' => $notes,
            'reference_type' => $saleId ? 'sale' : null,
            'reference_id' => $saleId,
            'user_id' => $actor->getKey(),
        ])->save();
        $customer->forceFill(['loyalty_points' => (string) $after->toScale(2)])->save();

        return $entry;
    }

    /**
     * Manual change (goodwill points, corrections). Signed; the balance never goes below zero.
     */
    public function adjust(Customer $customer, string $points, string $notes, User $actor): LoyaltyTransaction
    {
        $this->plan->assertFeature('loyalty', 'Loyalty points');

        return DB::transaction(function () use ($customer, $points, $notes, $actor) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $delta = BigDecimal::of($points)->toScale(2);
            $before = BigDecimal::of($customer->loyalty_points);
            $after = $before->plus($delta);

            if ($delta->isZero()) {
                throw ValidationException::withMessages(['points' => 'Enter points other than zero.']);
            }
            if ($after->isNegative()) {
                throw ValidationException::withMessages(['points' => "The customer only has {$before} points."]);
            }

            $entry = new LoyaltyTransaction;
            $entry->forceFill([
                'customer_id' => $customer->getKey(),
                'points' => (string) $delta,
                'balance_after' => (string) $after,
                'reason' => 'adjust',
                'notes' => $notes,
                'user_id' => $actor->getKey(),
            ])->save();

            $customer->forceFill(['loyalty_points' => (string) $after])->save();
            $this->audit->log('loyalty.adjusted', $customer, ['points' => (string) $before], ['points' => (string) $after], ['notes' => $notes]);

            return $entry;
        });
    }
}
