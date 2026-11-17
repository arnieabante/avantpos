<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Billing\PlanGate;
use App\Support\Api\ApiResponse;
use App\Support\Settings\PlatformSettings;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The business's own AvantPOS subscription: plan, usage, invoices, plan
 * changes and payments.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly PlanGate $plan,
        private readonly TenantContext $tenants,
    ) {}

    public function show(GatewayManager $gateways, PlatformSettings $settings): JsonResponse
    {
        $sub = $this->billing->subscription();
        $invoices = SubscriptionInvoice::query()->with(['plan', 'payments'])->where('subscription_id', $sub->id)
            ->latest('id')->limit(36)->get();

        return ApiResponse::data([
            'subscription' => [
                'status' => $sub->status->value,
                'plan' => ['code' => $sub->plan?->code, 'name' => $sub->plan?->name, 'free' => (bool) $sub->plan?->isFree()],
                'billing_cycle' => $sub->billing_cycle,
                'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                'current_period_start' => $sub->current_period_start?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
                'grace_ends_at' => $sub->grace_ends_at?->toIso8601String(),
                'cancel_at_period_end' => $sub->cancel_at_period_end,
                'pending_plan' => $sub->pendingPlan ? ['code' => $sub->pendingPlan->code, 'name' => $sub->pendingPlan->name] : null,
                'pending_billing_cycle' => $sub->pending_billing_cycle,
            ],
            'plans' => SubscriptionPlan::query()->with('features')->where('is_active', true)
                ->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $sub->subscription_plan_id))
                ->orderBy('sort_order')->orderBy('monthly_price')->get()
                ->map(fn (SubscriptionPlan $p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'description' => $p->description,
                    'monthly_price' => $p->monthly_price,
                    'annual_price' => $p->annual_price,
                    'trial_days' => $p->trial_days,
                    'limits' => $p->limits(),
                    'features' => $p->featureMap(),
                    'free' => $p->isFree(),
                    'current' => $p->id === $sub->subscription_plan_id,
                ])->all(),
            'usage' => collect(['branches', 'users', 'terminals', 'products'])->mapWithKeys(fn ($r) => [$r => $this->plan->usage($r)])->all(),
            'invoices' => $invoices->map(fn ($i) => $this->invoice($i))->all(),
            'payment' => [
                'gateway' => $gateways->default()->key(),
                'bank_details' => $settings->get('billing.bank_details'),
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
        ]);
        $plan = SubscriptionPlan::query()->where('code', $data['plan'])->firstOrFail();
        $result = $this->billing->checkout($plan, $data['billing_cycle'], $this->user($request));

        return ApiResponse::data([
            'invoice' => $result['invoice'] ? $this->invoice($result['invoice']->load(['plan', 'payments'])) : null,
            'checkout_url' => $result['checkout_url'],
            'scheduled' => $result['scheduled'],
            'message' => $result['message'],
        ], 201);
    }

    /** Open the payment page of an unpaid invoice again. */
    public function pay(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $url = $this->billing->startPayment($invoice, $this->tenants->requireTenant(), $this->user($request));

        return ApiResponse::data(['checkout_url' => $url, 'invoice' => $this->invoice($invoice->fresh()->load(['plan', 'payments']))]);
    }

    public function manualPayment(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->billing->submitManualPayment($invoice, $data, $this->user($request));

        return ApiResponse::data($this->invoice($invoice->fresh()->load(['plan', 'payments'])), 201);
    }

    public function invoiceShow(SubscriptionInvoice $invoice): JsonResponse
    {
        $tenant = $this->tenants->requireTenant();

        return ApiResponse::data($this->invoice($invoice->load(['plan', 'payments'])) + [
            'bill_to' => ['name' => $tenant->registered_name ?: $tenant->name, 'tin' => $tenant->tin, 'email' => $tenant->email, 'address' => $tenant->address_line],
            'vat_registered' => (bool) config('avantpos.billing.vat_registered'),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->billing->cancel($this->user($request));

        return ApiResponse::message('Your subscription will end at the end of the current period. You can resume it any time before then.');
    }

    public function resume(Request $request): JsonResponse
    {
        $this->billing->resume($this->user($request));

        return ApiResponse::message('Your subscription will continue.');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(SubscriptionInvoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'kind' => $i->kind,
            'plan' => $i->plan ? ['code' => $i->plan->code, 'name' => $i->plan->name] : null,
            'billing_cycle' => $i->billing_cycle,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'subtotal' => $i->subtotal,
            'credit' => $i->credit,
            'vat_amount' => $i->vat_amount,
            'total' => $i->total,
            'status' => $i->status,
            'issued_at' => $i->created_at?->toIso8601String(),
            'due_at' => $i->due_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'gateway' => $i->gateway,
            'checkout_url' => $i->status === 'open' ? $i->gateway_checkout_url : null,
            'payments' => $i->payments->map(fn ($p) => [
                'gateway' => $p->gateway, 'channel' => $p->channel, 'reference' => $p->gateway_reference, 'amount' => $p->amount,
                'status' => $p->status, 'paid_at' => $p->paid_at?->toIso8601String(), 'rejection_reason' => $p->rejection_reason,
            ])->all(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
