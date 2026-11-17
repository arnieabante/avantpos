<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\DomainException;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Gateways\GatewayManager;
use App\Support\Money\Money;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscriptions of businesses to AvantPOS plans.
 *
 * Plan changes
 *  - Upgrade (a plan that costs more per month) or a new paid plan after a
 *    trial / expiry: an invoice is issued; the new plan starts when it is
 *    paid. Unused time of the current paid period is credited.
 *  - Same plan and cycle: a renewal invoice; the paid period continues from
 *    the current end.
 *  - Downgrade (cheaper plan): takes effect at the end of the paid period
 *    (nothing to pay now).
 *
 * There is no free plan: new businesses start with a trial (see
 * config avantpos.app.trial).
 *
 * Lifecycle (run daily by `billing:run`)
 *  - Renewal invoice and reminder some days before the period ends.
 *  - Period ended unpaid → past due for the plan's grace days (still usable)
 *    → expired (read-only until paid).
 *  - Trial ended → past due with the grace days to pay.
 *  - Cancelled → ends at the period (or trial) end: read-only, data kept,
 *    a new plan can be chosen any time.
 *
 * Amounts are VAT-inclusive plan prices; VAT is shown separately when
 * AvantPOS is VAT-registered. Money is never handled as floats.
 */
class BillingService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /* ================================================================ checkout */

    /**
     * Start a plan change or renewal for the current business.
     *
     * @return array{invoice: SubscriptionInvoice|null, checkout_url: string|null, scheduled: bool, message: string}
     */
    public function checkout(SubscriptionPlan $plan, string $cycle, User $actor): array
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'This plan is no longer offered.']);
        }
        $tenant = $this->tenants->requireTenant();
        $sub = $this->subscription();
        $current = $sub->plan;
        $paidActive = $sub->status === SubscriptionStatus::Active && $current && ! $current->isFree()
            && $sub->current_period_end && $sub->current_period_end->isFuture();

        // Downgrades wait for the end of the paid period.
        $cheaper = $current && $this->monthly($plan, $cycle)->isLessThan($this->monthly($current, $sub->billing_cycle));
        if ($paidActive && ($plan->isFree() || ($cheaper && $plan->id !== $current->id))) {
            $sub->forceFill(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle, 'cancel_at_period_end' => false])->save();
            $this->history($sub, $current, $plan, 'downgrade', '0', $actor, $sub->current_period_end);
            $this->audit->log('billing.downgrade_scheduled', $sub, meta: ['to' => $plan->code, 'on' => $sub->current_period_end->toDateString()]);

            return ['invoice' => null, 'checkout_url' => null, 'scheduled' => true,
                'message' => "You will move to {$plan->name} on {$sub->current_period_end->toFormattedDateString()}."];
        }

        if ($plan->isFree()) {
            $this->switchTo($sub, $plan, 'monthly', 'downgrade', $actor);

            return ['invoice' => null, 'checkout_url' => null, 'scheduled' => false, 'message' => "You are now on the {$plan->name} plan."];
        }

        $renewal = $paidActive && $current->id === $plan->id && $sub->billing_cycle === $cycle;
        $kind = $renewal ? 'renewal' : ($paidActive ? 'upgrade' : 'new');
        $credit = $kind === 'upgrade' ? $this->unusedCredit($sub) : Money::zero();

        $invoice = DB::transaction(function () use ($sub, $plan, $cycle, $kind, $credit) {
            $this->voidOpenInvoices($sub);

            return $this->issueInvoice($sub, $plan, $cycle, $kind, $credit);
        });

        if (! Money::of($invoice->total)->isPositive()) {
            // The credit covers the whole upgrade.
            $this->markPaid($invoice, 'credit', null, null, '0.00', []);

            return ['invoice' => $invoice->fresh(), 'checkout_url' => null, 'scheduled' => false, 'message' => "You are now on the {$plan->name} plan."];
        }

        $url = $this->startPayment($invoice, $tenant, $actor);

        return ['invoice' => $invoice->fresh(), 'checkout_url' => $url, 'scheduled' => false,
            'message' => $url ? 'Continue to the payment page.' : 'Pay by bank transfer, then send us the reference number.'];
    }

    /** Open (or re-open) the payment page of an unpaid invoice. */
    public function startPayment(SubscriptionInvoice $invoice, Tenant $tenant, User $actor): ?string
    {
        if ($invoice->status !== 'open') {
            throw new DomainException('This invoice is not open for payment.', 'INVOICE_NOT_OPEN', 409);
        }
        $gateway = $this->gateways->default();
        $session = $gateway->checkout($invoice, $tenant->email ?: $actor->email, $tenant->registered_name ?: $tenant->name);
        $invoice->forceFill([
            'gateway' => $gateway->key(),
            'gateway_invoice_id' => $session->gatewayInvoiceId,
            'gateway_checkout_url' => $session->checkoutUrl,
        ])->save();

        return $session->checkoutUrl;
    }

    /** A business reports a bank transfer for an invoice; an admin verifies it. */
    public function submitManualPayment(SubscriptionInvoice $invoice, array $data, User $actor): BillingPayment
    {
        if ($invoice->status !== 'open') {
            throw new DomainException('This invoice is not open for payment.', 'INVOICE_NOT_OPEN', 409);
        }
        if ($invoice->payments()->where('status', 'pending')->exists()) {
            throw new DomainException('A payment for this invoice is already waiting for verification.', 'PAYMENT_PENDING', 409);
        }
        $payment = new BillingPayment;
        $payment->forceFill([
            'subscription_invoice_id' => $invoice->id,
            'gateway' => 'manual',
            'gateway_reference' => $data['reference'],
            'channel' => $data['channel'],
            'amount' => (string) Money::of($data['amount']),
            'status' => 'pending',
            'submitted_by' => $actor->getKey(),
            'raw_payload' => ['paid_on' => $data['paid_on'] ?? null, 'notes' => $data['notes'] ?? null],
        ])->save();
        $this->audit->log('billing.manual_payment_submitted', $invoice, meta: ['reference' => $data['reference'], 'amount' => (string) $payment->amount]);

        return $payment;
    }

    /* ================================================================ payment received */

    /**
     * Record a payment and apply the invoice to the subscription. Idempotent:
     * an invoice already paid is returned as is.
     *
     * @param  array<string, mixed>  $raw
     */
    public function markPaid(SubscriptionInvoice $invoice, string $gateway, ?string $reference, ?string $channel, ?string $amount, array $raw, ?PlatformAdmin $verifiedBy = null, ?BillingPayment $pending = null): SubscriptionInvoice
    {
        return $this->tenants->runAs($invoice->tenant_id, fn () => DB::transaction(function () use ($invoice, $gateway, $reference, $channel, $amount, $raw, $verifiedBy, $pending) {
            $invoice = SubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($invoice->status === 'paid') {
                return $invoice;
            }
            if ($amount !== null && $gateway !== 'credit' && Money::of($amount)->lessThan($invoice->total)) {
                throw new DomainException("The amount paid ({$amount}) is less than the invoice ({$invoice->total}).", 'UNDERPAID', 422);
            }

            $payment = $pending ?? new BillingPayment;
            $payment->forceFill([
                'subscription_invoice_id' => $invoice->id,
                'gateway' => $gateway,
                'gateway_reference' => $reference ?? $payment->gateway_reference,
                'channel' => $channel ?? $payment->channel,
                'amount' => (string) Money::of($amount ?? $invoice->total),
                'status' => 'paid',
                'paid_at' => now(),
                'verified_by' => $verifiedBy?->getKey(),
                'reviewed_at' => $verifiedBy ? now() : $payment->reviewed_at,
                'raw_payload' => $raw ?: $payment->raw_payload,
            ])->save();

            $sub = Subscription::query()->with('plan')->lockForUpdate()->findOrFail($invoice->subscription_id);
            $plan = SubscriptionPlan::query()->findOrFail($invoice->subscription_plan_id);
            $wasExpired = $sub->status === SubscriptionStatus::Expired;
            $continues = $invoice->kind === 'renewal' && $sub->current_period_end && $sub->current_period_end->isFuture();
            $start = $continues ? CarbonImmutable::parse($sub->current_period_end) : CarbonImmutable::now();
            $end = $invoice->billing_cycle === 'annual' ? $start->addYear() : $start->addMonth();

            $fromPlan = $sub->plan;
            $sub->forceFill([
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $invoice->billing_cycle,
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'pending_plan_id' => $sub->pending_plan_id === $plan->id ? null : $sub->pending_plan_id,
                'pending_billing_cycle' => $sub->pending_plan_id === $plan->id ? null : $sub->pending_billing_cycle,
            ])->save();
            $invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()])->save();

            $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
            if (in_array($tenant->status, [TenantStatus::Trial, TenantStatus::PastDue], true)
                || ($tenant->status === TenantStatus::Suspended && $tenant->suspension_reason === 'billing')) {
                $tenant->forceFill(['status' => TenantStatus::Active, 'suspended_at' => null, 'suspension_reason' => null])->save();
            }

            $type = $wasExpired ? 'reactivate' : ['renewal' => 'renew', 'upgrade' => 'upgrade', 'new' => 'created'][$invoice->kind] ?? 'renew';
            $this->history($sub, $fromPlan, $plan, $type, (string) $invoice->credit, $verifiedBy, now());
            $this->audit->log('billing.invoice_paid', $invoice, meta: ['number' => $invoice->number, 'total' => (string) $invoice->total, 'gateway' => $gateway], actor: $verifiedBy, tenantId: $invoice->tenant_id);

            return $invoice;
        }));
    }

    public function rejectManualPayment(BillingPayment $payment, string $reason, PlatformAdmin $admin): BillingPayment
    {
        if ($payment->status !== 'pending') {
            throw new DomainException('Only payments waiting for verification can be rejected.', 'PAYMENT_NOT_PENDING', 409);
        }
        $payment->forceFill(['status' => 'failed', 'rejection_reason' => $reason, 'reviewed_at' => now(), 'verified_by' => $admin->getKey()])->save();
        $this->audit->log('billing.manual_payment_rejected', $payment, meta: ['reason' => $reason], actor: $admin, tenantId: $payment->tenant_id);

        return $payment;
    }

    /* ================================================================ cancel / resume */

    public function cancel(User $actor): Subscription
    {
        $sub = $this->subscription();
        if (! in_array($sub->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw new DomainException('There is no running subscription to cancel.', 'NOTHING_TO_CANCEL', 409);
        }
        $sub->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now(), 'pending_plan_id' => null, 'pending_billing_cycle' => null])->save();
        $this->history($sub, $sub->plan, null, 'cancel', '0', $actor, $sub->current_period_end ?? $sub->trial_ends_at ?? now());
        DB::table('subscription_invoices')->where('subscription_id', $sub->id)->where('status', 'open')->where('kind', 'renewal')->update(['status' => 'void', 'updated_at' => now()]);
        $this->audit->log('billing.cancelled', $sub);

        return $sub;
    }

    public function resume(User $actor): Subscription
    {
        $sub = $this->subscription();
        $sub->forceFill(['cancel_at_period_end' => false, 'cancelled_at' => null, 'pending_plan_id' => null, 'pending_billing_cycle' => null])->save();
        $this->audit->log('billing.resumed', $sub);

        return $sub;
    }

    /* ================================================================ daily lifecycle */

    /**
     * Renewal invoices and reminders, trial ends, past due → expired,
     * scheduled downgrades and cancellations. Safe to run more than once a day.
     *
     * @return array<string, int>
     */
    public function runLifecycle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['invoices' => 0, 'past_due' => 0, 'expired' => 0, 'ended' => 0, 'reminders' => 0];
        $notice = (int) config('avantpos.billing.renewal_notice_days', 7);

        $subs = Subscription::withoutTenantScope()->with(['plan', 'pendingPlan', 'tenant'])
            ->whereIn('status', ['trialing', 'active', 'past_due', 'grace'])->get();

        foreach ($subs as $sub) {
            $this->tenants->runAs($sub->tenant_id, function () use ($sub, $now, $notice, &$counts) {
                $plan = $sub->plan;
                if (! $plan || ! $sub->tenant) {
                    return;
                }

                // Trial over.
                if ($sub->status === SubscriptionStatus::Trialing && $sub->trial_ends_at && $sub->trial_ends_at->lessThanOrEqualTo($now)) {
                    if ($plan->isFree()) {
                        $sub->forceFill(['status' => SubscriptionStatus::Active, 'trial_ends_at' => null])->save();

                        return;
                    }
                    if ($sub->cancel_at_period_end) {
                        $this->end($sub, $now);
                        $counts['ended']++;

                        return;
                    }
                    $this->pastDue($sub, $now);
                    $counts['past_due']++;
                    if ($this->ensureInvoice($sub, $plan, $sub->billing_cycle, 'new', $now)) {
                        $counts['invoices']++;
                    }
                    $this->notify($sub, 'trial_ended');

                    return;
                }

                if ($sub->status === SubscriptionStatus::Active && $sub->current_period_end) {
                    $end = CarbonImmutable::parse($sub->current_period_end);
                    $next = $sub->pendingPlan ?? $plan;
                    $cycle = $sub->pending_billing_cycle ?? $sub->billing_cycle;

                    if ($end->lessThanOrEqualTo($now)) {
                        if ($sub->cancel_at_period_end) {
                            $this->end($sub, $now);
                            $counts['ended']++;
                        } elseif ($next->isFree()) {
                            // Only if an admin created a zero-price plan.
                            $this->switchTo($sub, $next, 'monthly', 'downgrade', null);
                        } else {
                            $this->pastDue($sub, $now);
                            $counts['past_due']++;
                            if ($this->ensureInvoice($sub, $next, $cycle, 'renewal', $now)) {
                                $counts['invoices']++;
                            }
                            $this->notify($sub, 'past_due');
                        }

                        return;
                    }

                    // Renewal invoice and reminder before the end.
                    if (! $sub->cancel_at_period_end && ! $next->isFree() && $end->subDays($notice)->lessThanOrEqualTo($now)) {
                        if ($this->ensureInvoice($sub, $next, $cycle, 'renewal', $now, $end)) {
                            $counts['invoices']++;
                        }
                        if (! $sub->last_reminder_at || $sub->last_reminder_at->lessThan($end->subDays($notice))) {
                            $sub->forceFill(['last_reminder_at' => $now])->save();
                            $this->notify($sub, 'renewal_reminder');
                            $counts['reminders']++;
                        }
                    }

                    return;
                }

                // Grace period over: read-only until paid.
                if (in_array($sub->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Grace], true) && $sub->grace_ends_at && $sub->grace_ends_at->lessThanOrEqualTo($now)) {
                    $sub->forceFill(['status' => SubscriptionStatus::Expired])->save();
                    $this->history($sub, $plan, $plan, 'expire', '0', null, $now);
                    $this->audit->log('billing.expired', $sub, tenantId: $sub->tenant_id);
                    $this->notify($sub, 'expired');
                    $counts['expired']++;
                }
            });
        }

        return $counts;
    }

    /* ================================================================ helpers */

    public function subscription(): Subscription
    {
        return Subscription::query()->with(['plan.features', 'pendingPlan'])->latest('id')->first()
            ?? throw new DomainException('No subscription found for this business.', 'NO_SUBSCRIPTION', 404);
    }

    /** Price per month, to compare plans across billing cycles. */
    public function monthly(SubscriptionPlan $plan, string $cycle): BigDecimal
    {
        return $cycle === 'annual'
            ? BigDecimal::of($plan->annual_price)->dividedBy(12, 4, RoundingMode::HalfUp)
            : BigDecimal::of($plan->monthly_price);
    }

    /** Unused share of the current paid period, credited on an upgrade. */
    private function unusedCredit(Subscription $sub): Money
    {
        $start = $sub->current_period_start;
        $end = $sub->current_period_end;
        if (! $start || ! $end || ! $sub->plan || ! $end->isFuture()) {
            return Money::zero();
        }
        $total = max(1, $start->diffInSeconds($end));
        $left = max(0, (int) now()->diffInSeconds($end, false));
        $paid = Money::of($sub->plan->price($sub->billing_cycle));

        return $paid->multipliedBy((string) BigDecimal::of($left)->dividedBy($total, 10, RoundingMode::Down));
    }

    private function issueInvoice(Subscription $sub, SubscriptionPlan $plan, string $cycle, string $kind, Money $credit, ?CarbonImmutable $periodStart = null): SubscriptionInvoice
    {
        $price = Money::of($plan->price($cycle));
        $credit = $credit->min($price);
        $total = $price->minus($credit);
        $vat = config('avantpos.billing.vat_registered') ? $total->extractTax(config('avantpos.billing.vat_rate', '12'))[1] : Money::zero();
        $start = $periodStart ?? CarbonImmutable::now();
        $end = $cycle === 'annual' ? $start->addYear() : $start->addMonth();
        $due = $periodStart && $periodStart->isFuture() ? $periodStart : CarbonImmutable::now()->addDays((int) config('avantpos.billing.invoice_due_days', 7));

        $invoice = new SubscriptionInvoice;
        $invoice->forceFill([
            'tenant_id' => $sub->tenant_id,
            'subscription_id' => $sub->id,
            'number' => 'TMP-'.bin2hex(random_bytes(8)),
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'kind' => $kind,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'subtotal' => (string) $price,
            'credit' => (string) $credit,
            'vat_amount' => (string) $vat,
            'total' => (string) $total,
            'status' => 'open',
            'due_at' => $due,
        ])->save();
        // Platform-wide series: AVP-000001 (from the row id, so no two invoices share a number).
        $invoice->forceFill(['number' => 'AVP-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)])->save();

        return $invoice;
    }

    /** An open invoice for the next period, issued once. Returns whether one was created. */
    private function ensureInvoice(Subscription $sub, SubscriptionPlan $plan, string $cycle, string $kind, CarbonImmutable $now, ?CarbonImmutable $periodStart = null): bool
    {
        if (SubscriptionInvoice::query()->where('subscription_id', $sub->id)->where('status', 'open')->exists()) {
            return false;
        }
        $this->issueInvoice($sub, $plan, $cycle, $kind, Money::zero(), $periodStart);

        return true;
    }

    private function voidOpenInvoices(Subscription $sub): void
    {
        SubscriptionInvoice::query()->where('subscription_id', $sub->id)->where('status', 'open')
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', 'pending'))
            ->update(['status' => 'void', 'updated_at' => now()]);
        if (SubscriptionInvoice::query()->where('subscription_id', $sub->id)->where('status', 'open')->exists()) {
            throw new DomainException('A bank transfer for your current invoice is waiting for verification. Please wait for it before changing plans.', 'PAYMENT_PENDING', 409);
        }
    }

    private function pastDue(Subscription $sub, CarbonImmutable $now): void
    {
        $graceDays = (int) ($sub->plan?->grace_days ?? 0);
        $sub->forceFill(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => $now->addDays($graceDays)])->save();
        $tenant = $sub->tenant;
        if ($tenant && in_array($tenant->status, [TenantStatus::Trial, TenantStatus::Active], true)) {
            $tenant->forceFill(['status' => TenantStatus::PastDue])->save();
        }
        $this->audit->log('billing.past_due', $sub, meta: ['grace_ends_at' => $now->addDays($graceDays)->toDateString()], tenantId: $sub->tenant_id);
    }

    /** A cancelled subscription reached its end: read-only, data kept, open invoices voided. */
    private function end(Subscription $sub, CarbonImmutable $now): void
    {
        $sub->forceFill([
            'status' => SubscriptionStatus::Expired, 'trial_ends_at' => null, 'grace_ends_at' => null,
            'cancel_at_period_end' => false, 'pending_plan_id' => null, 'pending_billing_cycle' => null,
        ])->save();
        SubscriptionInvoice::query()->where('subscription_id', $sub->id)->where('status', 'open')->update(['status' => 'void', 'updated_at' => now()]);
        $this->history($sub, $sub->plan, $sub->plan, 'expire', '0', null, $now);
        $this->audit->log('billing.ended', $sub, tenantId: $sub->tenant_id);
        $this->notify($sub, 'ended');
    }

    private function switchTo(Subscription $sub, SubscriptionPlan $plan, string $cycle, string $type, ?User $actor): void
    {
        $from = $sub->plan;
        $sub->forceFill([
            'subscription_plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null, 'current_period_start' => now(), 'current_period_end' => null, 'grace_ends_at' => null,
            'cancel_at_period_end' => false, 'pending_plan_id' => null, 'pending_billing_cycle' => null,
        ])->save();
        SubscriptionInvoice::query()->where('subscription_id', $sub->id)->where('status', 'open')->update(['status' => 'void', 'updated_at' => now()]);
        $tenant = $sub->tenant ?? Tenant::query()->find($sub->tenant_id);
        if ($tenant && in_array($tenant->status, [TenantStatus::Trial, TenantStatus::PastDue], true)) {
            $tenant->forceFill(['status' => TenantStatus::Active])->save();
        }
        $this->history($sub, $from, $plan, $type, '0', $actor, now());
        $this->audit->log('billing.plan_changed', $sub, ['plan' => $from?->code], ['plan' => $plan->code], tenantId: $sub->tenant_id);
    }

    private function history(Subscription $sub, ?SubscriptionPlan $from, ?SubscriptionPlan $to, string $type, string $amount, mixed $actor, mixed $effective): void
    {
        $row = new SubscriptionHistory;
        $row->forceFill([
            'tenant_id' => $sub->tenant_id,
            'subscription_id' => $sub->id,
            'from_plan_id' => $from?->id,
            'to_plan_id' => $to?->id,
            'change_type' => $type,
            'prorated_amount' => (string) Money::of($amount),
            'actor_type' => $actor instanceof PlatformAdmin ? 'platform_admin' : ($actor instanceof User ? 'user' : 'system'),
            'actor_id' => $actor?->getKey(),
            'effective_at' => $effective ?? now(),
        ])->save();
    }

    private function notify(Subscription $sub, string $kind): void
    {
        $owner = $sub->tenant?->owner()->first();
        $owner?->notify(new SubscriptionNotice($kind, $sub->fresh(['plan', 'pendingPlan'])));
    }
}
