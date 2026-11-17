<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingPayment;
use App\Models\SubscriptionInvoice;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\GatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/billing/webhooks/{gateway} — payment notifications from Xendit /
 * PayMongo. Verified (token / signature), stored once per event id, then
 * applied. Always answers quickly; failures are kept on the stored event.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, BillingService $billing): JsonResponse
    {
        $driver = $gateways->get($gateway);
        if (! $driver->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $result = $driver->parseWebhook($request);
        if (! $result) {
            return response()->json(['message' => 'Ignored.']);
        }

        try {
            $event = WebhookEvent::query()->create([
                'gateway' => $gateway, 'event_id' => $result->eventId, 'event_type' => $result->eventType,
                'payload' => $result->raw, 'status' => 'received',
            ]);
        } catch (QueryException) {
            return response()->json(['message' => 'Already processed.']); // re-delivered event
        }

        try {
            $invoice = SubscriptionInvoice::withoutTenantScope()
                ->where(fn ($q) => $q->where('number', $result->invoiceNumber)->when($result->gatewayInvoiceId, fn ($w) => $w->orWhere('gateway_invoice_id', $result->gatewayInvoiceId)))
                ->first();
            if (! $invoice) {
                $event->update(['status' => 'ignored', 'error' => 'No matching invoice.', 'processed_at' => now()]);

                return response()->json(['message' => 'Unknown invoice.']);
            }

            if ($result->status === 'paid') {
                $billing->markPaid($invoice, $gateway, $result->reference, $result->channel, $result->amount, $result->raw);
            } elseif ($result->status === 'failed') {
                $payment = new BillingPayment;
                $payment->forceFill([
                    'tenant_id' => $invoice->tenant_id, 'subscription_invoice_id' => $invoice->id, 'gateway' => $gateway,
                    'gateway_reference' => $result->reference, 'channel' => $result->channel, 'amount' => $result->amount ?? $invoice->total,
                    'status' => 'failed', 'raw_payload' => $result->raw,
                ])->save();
            } else {
                // The payment page expired: a new one is opened when the business pays again.
                $invoice->forceFill(['gateway_checkout_url' => null])->save();
            }
            $event->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'processed_at' => now()]);
            report($e);

            return response()->json(['message' => 'Could not apply the event.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }
}
