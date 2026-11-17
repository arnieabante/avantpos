<?php

namespace App\Http\Controllers\Admin\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Models\PlanFeature;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\PayMongoGateway;
use App\Services\Billing\Gateways\XenditGateway;
use App\Services\Support\SupportService;
use App\Support\Api\ApiResponse;
use App\Support\Settings\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform administration: subscription plans, payments and invoices,
 * activity log, platform settings and support tickets.
 */
class PlatformController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /* ================================================================ plans */

    public function plans(): JsonResponse
    {
        $counts = Subscription::withoutTenantScope()->groupBy('subscription_plan_id')->selectRaw('subscription_plan_id, COUNT(*) AS n')->pluck('n', 'subscription_plan_id');

        return ApiResponse::data([
            'plans' => SubscriptionPlan::query()->with('features')->orderBy('sort_order')->orderBy('monthly_price')->get()
                ->map(fn (SubscriptionPlan $p) => $this->plan($p) + ['businesses' => (int) ($counts[$p->id] ?? 0)])->all(),
            'feature_keys' => config('avantpos.plans.feature_keys'),
        ]);
    }

    public function savePlan(Request $request, ?SubscriptionPlan $plan = null): JsonResponse
    {
        $limit = ['nullable', 'integer', 'min:1', 'max:1000000'];
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('subscription_plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'monthly_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'annual_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'max_branches' => $limit, 'max_users' => $limit, 'max_terminals' => $limit, 'max_products' => $limit,
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'features' => ['array'],
            'features.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['features'] ?? []), (array) config('avantpos.plans.feature_keys'));
        if ($unknown !== []) {
            throw new DomainException('Unknown features: '.implode(', ', $unknown), 'UNKNOWN_FEATURE', 422);
        }

        $data['sort_order'] ??= 0;
        $plan ??= new SubscriptionPlan;
        $old = $plan->exists ? $this->plan($plan->load('features')) : [];
        DB::transaction(function () use ($plan, $data) {
            $plan->forceFill(collect($data)->except('features')->all() + ['is_public' => $data['is_public'] ?? true, 'is_active' => $data['is_active'] ?? true])->save();
            foreach ($data['features'] ?? [] as $key => $enabled) {
                PlanFeature::query()->updateOrCreate(['subscription_plan_id' => $plan->id, 'feature_key' => $key], ['enabled' => (bool) $enabled]);
            }
        });
        $this->audit->log($old ? 'platform.plan_updated' : 'platform.plan_created', $plan, $old, $this->plan($plan->fresh('features')), actor: $this->admin($request));

        return ApiResponse::data($this->plan($plan->fresh('features')), $old ? 200 : 201);
    }

    /* ================================================================ billing */

    public function payments(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'paid', 'failed'])], 'per_page' => ['nullable', 'integer', 'max:100']]);
        $page = BillingPayment::withoutTenantScope()->with(['tenant', 'invoice.plan', 'verifier'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')->paginate($request->integer('per_page', 25));

        return $this->page($page, fn (BillingPayment $p) => [
            'id' => $p->id,
            'tenant' => $p->tenant ? ['id' => $p->tenant->id, 'name' => $p->tenant->name] : null,
            'invoice' => $p->invoice ? ['id' => $p->invoice->id, 'number' => $p->invoice->number, 'total' => $p->invoice->total, 'plan' => $p->invoice->plan?->name, 'status' => $p->invoice->status] : null,
            'gateway' => $p->gateway, 'channel' => $p->channel, 'reference' => $p->gateway_reference, 'amount' => $p->amount,
            'status' => $p->status, 'paid_at' => $p->paid_at?->toIso8601String(), 'submitted_at' => $p->created_at?->toIso8601String(),
            'paid_on' => $p->raw_payload['paid_on'] ?? null, 'notes' => $p->raw_payload['notes'] ?? null,
            'verified_by' => $p->verifier?->name, 'rejection_reason' => $p->rejection_reason,
        ]);
    }

    public function verifyPayment(Request $request, BillingService $billing, int $payment): JsonResponse
    {
        $row = BillingPayment::withoutTenantScope()->findOrFail($payment);
        if ($row->status !== 'pending') {
            throw new DomainException('Only payments waiting for verification can be verified.', 'PAYMENT_NOT_PENDING', 409);
        }
        $invoice = SubscriptionInvoice::withoutTenantScope()->findOrFail($row->subscription_invoice_id);
        $billing->markPaid($invoice, 'manual', $row->gateway_reference, $row->channel, (string) $row->amount, [], $this->admin($request), $row);

        return ApiResponse::message("Payment verified. Invoice {$invoice->number} is paid and the subscription is active.");
    }

    public function rejectPayment(Request $request, BillingService $billing, int $payment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $billing->rejectManualPayment(BillingPayment::withoutTenantScope()->findOrFail($payment), $reason, $this->admin($request));

        return ApiResponse::message('Payment rejected.');
    }

    public function invoices(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['open', 'paid', 'void', 'expired'])], 'tenant_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'max:100']]);
        $page = SubscriptionInvoice::withoutTenantScope()->with(['tenant', 'plan'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->latest('id')->paginate($request->integer('per_page', 25));

        return $this->page($page, fn (SubscriptionInvoice $i) => [
            'id' => $i->id, 'number' => $i->number, 'tenant' => $i->tenant ? ['id' => $i->tenant->id, 'name' => $i->tenant->name] : null,
            'plan' => $i->plan?->name, 'billing_cycle' => $i->billing_cycle, 'kind' => $i->kind, 'total' => $i->total, 'status' => $i->status,
            'gateway' => $i->gateway, 'issued_at' => $i->created_at?->toIso8601String(), 'due_at' => $i->due_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
        ]);
    }

    /* ================================================================ activity log */

    public function activity(Request $request): JsonResponse
    {
        $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:60'],
            'scope' => ['nullable', Rule::in(['platform', 'tenants'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'max:100'],
        ]);
        $page = AuditLog::query()
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->input('scope') === 'platform', fn ($q) => $q->whereNull('tenant_id'))
            ->when($request->input('scope') === 'tenants', fn ($q) => $q->whereNotNull('tenant_id'))
            ->when($request->filled('event'), fn ($q) => $q->where('event', 'like', addcslashes((string) $request->input('event'), '%_\\').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->input('from').' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->input('to').' 23:59:59'))
            ->latest('id')->paginate($request->integer('per_page', 50));
        $tenants = Tenant::query()->whereIn('id', $page->getCollection()->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        return $this->page($page, fn (AuditLog $l) => [
            'id' => $l->id, 'event' => $l->event, 'tenant' => $l->tenant_id ? ['id' => $l->tenant_id, 'name' => $tenants[$l->tenant_id] ?? null] : null,
            'actor' => ['type' => $l->actor_type, 'name' => $l->actor_name], 'ip' => $l->ip_address ?? null,
            'subject' => $l->auditable_type ? class_basename($l->auditable_type).' #'.$l->auditable_id : null,
            'meta' => $l->meta ?? null, 'created_at' => $l->created_at?->toIso8601String(),
        ]);
    }

    /* ================================================================ settings */

    public function settings(PlatformSettings $settings): JsonResponse
    {
        return ApiResponse::data($settings->all() + ['gateways' => [
            'active' => config('avantpos.billing.gateway'),
            'xendit_configured' => app(XenditGateway::class)->available(),
            'paymongo_configured' => app(PayMongoGateway::class)->available(),
        ]]);
    }

    public function saveSettings(Request $request, PlatformSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'billing' => ['sometimes', 'array'],
            'billing.bank_details' => ['nullable', 'string', 'max:2000'],
            'support' => ['sometimes', 'array'],
            'support.email' => ['nullable', 'email', 'max:150'],
            'support.phone' => ['nullable', 'string', 'max:30'],
            'platform' => ['sometimes', 'array'],
            'platform.announcement' => ['nullable', 'string', 'max:500'],
            'platform.registrations_open' => ['sometimes', 'boolean'],
        ]);
        $old = $settings->all();
        foreach ($data as $group => $values) {
            $settings->save($group, $values);
        }
        $this->audit->log('platform.settings_updated', null, $old, $settings->all(), actor: $this->admin($request));

        return $this->settings($settings);
    }

    /* ================================================================ support tickets */

    public function tickets(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(SupportTicket::STATUSES)], 'per_page' => ['nullable', 'integer', 'max:100']]);
        $page = SupportTicket::withoutTenantScope()->with(['tenant', 'user', 'assignee'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByRaw("FIELD(status, 'open', 'pending', 'resolved', 'closed')")
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")->latest('updated_at')
            ->paginate($request->integer('per_page', 25));

        return $this->page($page, fn (SupportTicket $t) => $this->ticket($t));
    }

    public function showTicket(int $ticket): JsonResponse
    {
        $t = SupportTicket::withoutTenantScope()->with(['tenant', 'user', 'assignee'])->findOrFail($ticket);
        $messages = DB::table('ticket_messages')->where('support_ticket_id', $t->id)->orderBy('id')->get();
        $admins = PlatformAdmin::query()->pluck('name', 'id');
        $users = DB::table('users')->where('tenant_id', $t->tenant_id)->pluck('name', 'id');

        return ApiResponse::data($this->ticket($t) + ['messages' => $messages->map(fn ($m) => [
            'id' => $m->id, 'from' => $m->author_type === 'platform_admin' ? 'support' : 'business',
            'author' => $m->author_type === 'platform_admin' ? ($admins[$m->author_id] ?? 'Admin') : ($users[$m->author_id] ?? 'User'),
            'body' => $m->body, 'created_at' => $m->created_at,
        ])->all()]);
    }

    public function replyTicket(Request $request, SupportService $support, int $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000'], 'status' => ['nullable', Rule::in(['pending', 'resolved', 'closed'])]]);
        $support->replyAsAdmin(SupportTicket::withoutTenantScope()->findOrFail($ticket), $data['body'], $data['status'] ?? null, $this->admin($request));

        return $this->showTicket($ticket);
    }

    public function updateTicket(Request $request, SupportService $support, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('platform_admins', 'id')],
        ]);
        $support->update(SupportTicket::withoutTenantScope()->findOrFail($ticket), $data, $this->admin($request));

        return $this->showTicket($ticket);
    }

    /* ================================================================ helpers */

    /**
     * @return array<string, mixed>
     */
    private function plan(SubscriptionPlan $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description,
            'monthly_price' => $p->monthly_price, 'annual_price' => $p->annual_price,
            'trial_days' => $p->trial_days, 'grace_days' => $p->grace_days,
            'max_branches' => $p->max_branches, 'max_users' => $p->max_users, 'max_terminals' => $p->max_terminals, 'max_products' => $p->max_products,
            'is_public' => (bool) $p->is_public, 'is_active' => (bool) $p->is_active, 'sort_order' => $p->sort_order,
            'features' => $p->featureMap(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(SupportTicket $t): array
    {
        return [
            'id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
            'tenant' => $t->tenant ? ['id' => $t->tenant->id, 'name' => $t->tenant->name] : null,
            'opened_by' => $t->user ? ['name' => $t->user->name, 'email' => $t->user->email] : null,
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'created_at' => $t->created_at?->toIso8601String(), 'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    private function page(LengthAwarePaginator $page, callable $present): JsonResponse
    {
        return ApiResponse::data($page->getCollection()->map($present)->all(), 200, ['pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return $admin;
    }
}
