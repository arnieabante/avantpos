<?php

namespace App\Services\Billing\Gateways;

use App\Exceptions\DomainException;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayMongo Checkout Sessions (GCash, Maya, cards, GrabPay, online banking).
 * Amounts are in centavos. Webhooks are signed: Paymongo-Signature header
 * "t=<timestamp>,te=<test sig>,li=<live sig>", HMAC-SHA256 of "<t>.<raw body>".
 */
class PayMongoGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'paymongo';
    }

    public function available(): bool
    {
        return filled(config('avantpos.billing.gateways.paymongo.secret_key')) && filled(config('avantpos.billing.gateways.paymongo.webhook_secret'));
    }

    public function checkout(SubscriptionInvoice $invoice, string $customerEmail, string $customerName): CheckoutSession
    {
        $config = config('avantpos.billing.gateways.paymongo');
        // "1999.00" → 199900 centavos, without going through floats.
        $centavos = (int) str_replace('.', '', (string) $invoice->total);
        $response = Http::withBasicAuth((string) $config['secret_key'], '')->acceptJson()->timeout(20)
            ->post(rtrim($config['base_url'], '/').'/v1/checkout_sessions', ['data' => ['attributes' => [
                'reference_number' => $invoice->number,
                'description' => "AvantPOS subscription {$invoice->number}",
                'billing' => ['email' => $customerEmail, 'name' => $customerName],
                'line_items' => [['name' => "AvantPOS {$invoice->plan?->name} ({$invoice->billing_cycle})", 'quantity' => 1, 'amount' => $centavos, 'currency' => 'PHP']],
                'payment_method_types' => ['gcash', 'paymaya', 'card', 'grab_pay', 'dob'],
                'success_url' => config('avantpos.billing.return_url').'?paid='.$invoice->number,
                'cancel_url' => config('avantpos.billing.return_url'),
                'send_email_receipt' => true,
            ]]]);

        if ($response->failed()) {
            throw new DomainException('The payment page could not be opened. Please try again in a moment.', 'GATEWAY_ERROR', 502, ['gateway' => 'paymongo', 'status' => $response->status()]);
        }

        return new CheckoutSession($response->json('data.id'), $response->json('data.attributes.checkout_url'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('avantpos.billing.gateways.paymongo.webhook_secret');
        $parts = [];
        foreach (explode(',', (string) $request->header('Paymongo-Signature')) as $piece) {
            [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }
        if ($secret === '' || empty($parts['t'])) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'].'.'.$request->getContent(), $secret);
        $given = ($parts['li'] ?? '') !== '' ? $parts['li'] : ($parts['te'] ?? '');

        return $given !== '' && hash_equals($expected, $given);
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $event = $request->json('data');
        $type = (string) data_get($event, 'attributes.type');
        if (! in_array($type, ['checkout_session.payment.paid', 'payment.failed'], true)) {
            return null;
        }
        $session = data_get($event, 'attributes.data');
        $payment = $type === 'checkout_session.payment.paid' ? data_get($session, 'attributes.payments.0') : $session;
        $centavos = data_get($payment, 'attributes.amount');

        return new WebhookResult(
            eventId: (string) data_get($event, 'id'),
            eventType: $type,
            status: $type === 'payment.failed' ? 'failed' : 'paid',
            gatewayInvoiceId: $type === 'checkout_session.payment.paid' ? data_get($session, 'id') : null,
            invoiceNumber: data_get($session, 'attributes.reference_number'),
            amount: $centavos !== null ? intdiv((int) $centavos, 100).'.'.str_pad((string) (((int) $centavos) % 100), 2, '0', STR_PAD_LEFT) : null,
            channel: data_get($payment, 'attributes.source.type') ?? data_get($payment, 'attributes.payment_method_used'),
            reference: data_get($payment, 'id'),
            raw: (array) $request->json()->all(),
        );
    }
}
