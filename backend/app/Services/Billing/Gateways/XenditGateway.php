<?php

namespace App\Services\Billing\Gateways;

use App\Exceptions\DomainException;
use App\Models\SubscriptionInvoice;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Xendit Invoices API (GCash, Maya, cards, bank transfers, over-the-counter).
 * Checkout: POST /v2/invoices with the invoice number as external_id.
 * Webhook: authenticated by the x-callback-token header.
 */
class XenditGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'xendit';
    }

    public function available(): bool
    {
        return filled(config('avantpos.billing.gateways.xendit.secret_key')) && filled(config('avantpos.billing.gateways.xendit.callback_token'));
    }

    public function checkout(SubscriptionInvoice $invoice, string $customerEmail, string $customerName): CheckoutSession
    {
        $config = config('avantpos.billing.gateways.xendit');
        $response = Http::withBasicAuth((string) $config['secret_key'], '')->acceptJson()->timeout(20)
            ->post(rtrim($config['base_url'], '/').'/v2/invoices', [
                'external_id' => $invoice->number,
                // Xendit takes a JSON number; the amount has at most 2 decimals.
                'amount' => (float) $invoice->total,
                'currency' => 'PHP',
                'description' => "AvantPOS subscription {$invoice->number}",
                'payer_email' => $customerEmail,
                'customer' => ['given_names' => $customerName, 'email' => $customerEmail],
                'success_redirect_url' => $this->returnUrl($invoice).'?paid='.$invoice->number,
                'failure_redirect_url' => $this->returnUrl($invoice),
                'invoice_duration' => max(3600, (int) now()->diffInSeconds($invoice->due_at, false)),
            ]);

        if ($response->failed()) {
            throw new DomainException('The payment page could not be opened. Please try again in a moment.', 'GATEWAY_ERROR', 502, ['gateway' => 'xendit', 'status' => $response->status()]);
        }

        return new CheckoutSession($response->json('id'), $response->json('invoice_url'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('avantpos.billing.gateways.xendit.callback_token');

        return $token !== '' && hash_equals($token, (string) $request->header('x-callback-token'));
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $p = $request->json()->all();
        if (! isset($p['id'], $p['external_id'], $p['status'])) {
            return null;
        }
        $status = match (strtoupper((string) $p['status'])) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            default => null,
        };
        if ($status === null) {
            return null;
        }
        $amount = $p['paid_amount'] ?? $p['amount'] ?? null;

        return new WebhookResult(
            eventId: $p['id'].':'.strtolower((string) $p['status']),
            eventType: 'invoice.'.strtolower((string) $p['status']),
            status: $status,
            gatewayInvoiceId: (string) $p['id'],
            invoiceNumber: (string) $p['external_id'],
            amount: $amount !== null ? number_format((float) $amount, 2, '.', '') : null,
            channel: $p['payment_channel'] ?? $p['payment_method'] ?? null,
            reference: $p['payment_id'] ?? (string) $p['id'],
            raw: $p,
        );
    }

    /** Back to the business's own Subscription page (e.g. https://trd-motorshop.avantpos.co/settings/billing). */
    private function returnUrl(SubscriptionInvoice $invoice): string
    {
        return app(FrontendUrl::class)->forTenantId($invoice->tenant_id).'/settings/billing';
    }
}
