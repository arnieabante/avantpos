<?php

namespace App\Http\Controllers\Admin\V1;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Businesses on the platform: list, details, activation / suspension and
 * subscription adjustments (e.g. extend a trial, complimentary period).
 */
class TenantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(TenantStatus::cases(), 'value'))],
            'plan' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = trim((string) $request->input('search'));
        $page = Tenant::query()->with(['subscription.plan', 'owner'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('slug', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('email', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('plan'), fn ($q) => $q->whereHas('subscription', fn ($s) => $s
                ->whereIn('subscription_plan_id', SubscriptionPlan::query()->where('code', $request->input('plan'))->select('id'))))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        $counts = $this->counts($page->getCollection()->pluck('id')->all());

        return ApiResponse::data($page->getCollection()->map(fn (Tenant $t) => $this->summary($t, $counts))->all(), 200, ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['subscription.plan', 'subscription.pendingPlan', 'owner']);
        $sub = $tenant->subscription;
        $counts = $this->counts([$tenant->id]);
        $sales = DB::table('sales')->where('tenant_id', $tenant->id)->where('status', '!=', 'voided');

        return ApiResponse::data($this->summary($tenant, $counts) + [
            'registered_name' => $tenant->registered_name,
            'tin' => $tenant->tin,
            'vat_registered' => (bool) $tenant->vat_registered,
            'phone' => $tenant->phone,
            'business_type' => $tenant->business_type,
            'suspended_at' => $tenant->suspended_at?->toIso8601String(),
            'suspension_reason' => $tenant->suspension_reason,
            'activity' => [
                'sales_30_days' => (clone $sales)->where('sold_at', '>=', now()->subDays(30))->count(),
                'last_sale_at' => (clone $sales)->max('sold_at'),
                'last_login_at' => DB::table('users')->where('tenant_id', $tenant->id)->max('last_login_at'),
            ],
            'subscription' => $sub ? [
                'status' => $sub->status->value, 'plan' => ['code' => $sub->plan?->code, 'name' => $sub->plan?->name],
                'billing_cycle' => $sub->billing_cycle, 'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                'current_period_start' => $sub->current_period_start?->toIso8601String(), 'current_period_end' => $sub->current_period_end?->toIso8601String(),
                'grace_ends_at' => $sub->grace_ends_at?->toIso8601String(), 'cancel_at_period_end' => $sub->cancel_at_period_end,
                'pending_plan' => $sub->pendingPlan?->name,
            ] : null,
            'invoices' => SubscriptionInvoice::withoutTenantScope()->with('plan')->where('tenant_id', $tenant->id)->latest('id')->limit(24)->get()
                ->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'plan' => $i->plan?->name, 'total' => $i->total,
                    'status' => $i->status, 'due_at' => $i->due_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String()])->all(),
            'history' => SubscriptionHistory::withoutTenantScope()->where('tenant_id', $tenant->id)->latest('id')->limit(30)->get()
                ->map(fn ($h) => ['type' => $h->change_type, 'from' => SubscriptionPlan::query()->whereKey($h->from_plan_id)->value('name'),
                    'to' => SubscriptionPlan::query()->whereKey($h->to_plan_id)->value('name'), 'by' => $h->actor_type,
                    'effective_at' => $h->effective_at?->toIso8601String()])->all(),
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        if ($tenant->status === TenantStatus::Suspended) {
            throw new DomainException('This business is already suspended.', 'ALREADY_SUSPENDED', 409);
        }
        $old = $tenant->status->value;
        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
        $this->audit->log('platform.tenant_suspended', $tenant, ['status' => $old], ['status' => 'suspended'], ['reason' => $reason], actor: $this->admin($request), tenantId: $tenant->id);

        return $this->show($tenant);
    }

    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        $sub = Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->latest('id')->first();
        $status = match ($sub?->status) {
            SubscriptionStatus::Trialing => TenantStatus::Trial,
            SubscriptionStatus::PastDue, SubscriptionStatus::Grace => TenantStatus::PastDue,
            default => TenantStatus::Active,
        };
        $old = $tenant->status->value;
        $tenant->forceFill(['status' => $status, 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->audit->log('platform.tenant_activated', $tenant, ['status' => $old], ['status' => $status->value], actor: $this->admin($request), tenantId: $tenant->id);

        return $this->show($tenant);
    }

    /**
     * Adjust a subscription by hand: change plan / cycle, extend a trial or
     * the paid period (e.g. a complimentary month). Recorded in the history.
     */
    public function updateSubscription(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'expired'])],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trialing'],
            'current_period_end' => ['nullable', 'date'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $sub = Subscription::withoutTenantScope()->with('plan')->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $plan = SubscriptionPlan::query()->where('code', $data['plan'])->firstOrFail();
        $before = ['plan' => $sub->plan?->code, 'status' => $sub->status->value, 'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(), 'current_period_end' => $sub->current_period_end?->toIso8601String()];

        DB::transaction(function () use ($sub, $plan, $data, $tenant, $request) {
            $sub->forceFill([
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $data['billing_cycle'],
                'status' => $data['status'],
                'trial_ends_at' => $data['status'] === 'trialing' ? $data['trial_ends_at'] : null,
                'current_period_start' => $data['status'] === 'active' ? ($sub->current_period_start ?? now()) : $sub->current_period_start,
                'current_period_end' => $data['current_period_end'] ?? ($data['status'] === 'active' && $plan->isFree() ? null : $sub->current_period_end),
                'grace_ends_at' => $data['status'] === 'past_due' ? now()->addDays($plan->grace_days) : null,
            ])->save();
            $history = new SubscriptionHistory;
            $history->forceFill([
                'tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'from_plan_id' => $sub->getOriginal('subscription_plan_id'), 'to_plan_id' => $plan->id,
                'change_type' => 'admin', 'prorated_amount' => '0.00', 'actor_type' => 'platform_admin', 'actor_id' => $this->admin($request)->getKey(), 'effective_at' => now(),
            ])->save();
            if ($tenant->status !== TenantStatus::Suspended) {
                $tenant->forceFill(['status' => match ($data['status']) {
                    'trialing' => TenantStatus::Trial, 'past_due' => TenantStatus::PastDue, default => TenantStatus::Active,
                }])->save();
            }
        });
        $this->audit->log('platform.subscription_adjusted', $tenant, $before, $data, actor: $this->admin($request), tenantId: $tenant->id);

        return $this->show($tenant->fresh());
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, array<int, int>>
     */
    private function counts(array $ids): array
    {
        $count = fn (string $table, ?callable $scope = null) => DB::table($table)->whereIn('tenant_id', $ids)
            ->when($scope, $scope)->groupBy('tenant_id')->selectRaw('tenant_id, COUNT(*) AS n')->pluck('n', 'tenant_id')->all();

        return [
            'branches' => $count('branches', fn ($q) => $q->whereNull('deleted_at')),
            'users' => $count('users', fn ($q) => $q->whereNull('deleted_at')),
            'terminals' => $count('terminals', fn ($q) => $q->where('status', '!=', 'retired')),
            'products' => $count('products', fn ($q) => $q->whereNull('deleted_at')),
        ];
    }

    /**
     * @param  array<string, array<int, int>>  $counts
     * @return array<string, mixed>
     */
    private function summary(Tenant $t, array $counts): array
    {
        $sub = $t->subscription;

        return [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'subdomain' => $t->subdomain,
            'email' => $t->email,
            'status' => $t->status->value,
            'owner' => $t->owner ? ['name' => $t->owner->name, 'email' => $t->owner->email] : null,
            'plan' => $sub?->plan?->name,
            'subscription_status' => $sub?->status->value,
            'ends_at' => ($sub?->status === SubscriptionStatus::Trialing ? $sub->trial_ends_at : $sub?->current_period_end)?->toIso8601String(),
            'usage' => collect($counts)->map(fn ($c) => (int) ($c[$t->id] ?? 0))->all(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return $admin;
    }
}
