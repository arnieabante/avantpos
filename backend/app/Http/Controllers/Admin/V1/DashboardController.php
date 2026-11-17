<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Support\Api\ApiResponse;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Platform overview: businesses, subscriptions, recurring revenue, sign-ups,
 * expiring subscriptions, payments and support.
 *
 * MRR = monthly value of paying subscriptions (active, past due or in grace,
 * on a plan that costs something): the monthly price, or the annual price ÷ 12.
 * ARR = MRR × 12.
 */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $subs = Subscription::withoutTenantScope()->with(['plan', 'tenant'])->whereHas('tenant')->get();

        $mrr = BigDecimal::zero();
        $paying = 0;
        foreach ($subs as $s) {
            if (! in_array($s->status->value, ['active', 'past_due', 'grace'], true) || ! $s->plan || $s->plan->isFree()) {
                continue;
            }
            $paying++;
            $mrr = $mrr->plus($s->billing_cycle === 'annual'
                ? BigDecimal::of($s->plan->annual_price)->dividedBy(12, 2, RoundingMode::HalfUp)
                : BigDecimal::of($s->plan->monthly_price));
        }

        $signups = Tenant::query()->where('created_at', '>=', $now->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')->groupByRaw('DATE(created_at)')->pluck('n', 'd');
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $now->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'signups' => (int) ($signups[$d] ?? 0)];
        }

        $expiring = $subs->filter(fn ($s) => ($s->status->value === 'trialing' && $s->trial_ends_at && $s->trial_ends_at->between($now, $now->addDays(7)))
            || ($s->status->value === 'active' && $s->current_period_end && $s->current_period_end->between($now, $now->addDays(14)))
            || in_array($s->status->value, ['past_due', 'grace'], true))
            ->sortBy(fn ($s) => $s->current_period_end ?? $s->trial_ends_at ?? $s->grace_ends_at)
            ->take(20)->map(fn ($s) => [
                'tenant' => ['id' => $s->tenant->id, 'name' => $s->tenant->name],
                'plan' => $s->plan?->name, 'status' => $s->status->value,
                'ends_at' => ($s->status->value === 'trialing' ? $s->trial_ends_at : ($s->grace_ends_at ?? $s->current_period_end))?->toIso8601String(),
                'cancel_at_period_end' => $s->cancel_at_period_end,
            ])->values()->all();

        $paid = BillingPayment::withoutTenantScope()->where('status', 'paid')->where('paid_at', '>=', $now->subDays(30));

        return ApiResponse::data([
            'tenants' => [
                'total' => Tenant::query()->count(),
                'by_status' => Tenant::query()->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
                'new_30_days' => array_sum(array_column($series, 'signups')),
                'new_7_days' => array_sum(array_column(array_slice($series, -7), 'signups')),
            ],
            'subscriptions' => [
                'by_status' => $subs->groupBy(fn ($s) => $s->status->value)->map->count(),
                'by_plan' => $subs->groupBy(fn ($s) => $s->plan?->name ?? '—')->map->count(),
                'paying' => $paying,
            ],
            'mrr' => (string) $mrr->toScale(2),
            'arr' => (string) $mrr->multipliedBy(12)->toScale(2),
            'signups' => $series,
            'expiring' => $expiring,
            'payments' => [
                'last_30_days' => (string) Money::of((clone $paid)->sum('amount') ?: '0'),
                'count_30_days' => (clone $paid)->count(),
                'pending_verification' => BillingPayment::withoutTenantScope()->where('status', 'pending')->count(),
                'recent' => BillingPayment::withoutTenantScope()->with(['tenant', 'invoice'])->whereIn('status', ['paid', 'pending'])->latest('id')->limit(8)->get()
                    ->map(fn ($p) => [
                        'id' => $p->id, 'tenant' => $p->tenant?->name, 'invoice' => $p->invoice?->number, 'amount' => $p->amount,
                        'gateway' => $p->gateway, 'channel' => $p->channel, 'status' => $p->status, 'at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    ])->all(),
            ],
            'support' => [
                'open' => SupportTicket::withoutTenantScope()->whereIn('status', ['open'])->count(),
                'waiting' => SupportTicket::withoutTenantScope()->where('status', 'pending')->count(),
            ],
        ]);
    }
}
