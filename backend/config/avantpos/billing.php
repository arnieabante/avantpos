<?php

/*
|--------------------------------------------------------------------------
| SaaS billing (AvantPOS → businesses)
|--------------------------------------------------------------------------
|
| Payment gateways are plug-ins behind App\Services\Billing\Gateways\PaymentGateway.
| `manual` (bank transfer / GCash to AvantPOS, verified by an admin) is always
| available; Xendit and PayMongo are used when their keys are set. Keys are
| read from the environment only — never stored in the database or code.
|
*/

return [

    // Gateway offered at checkout: xendit, paymongo or manual.
    'gateway' => env('BILLING_GATEWAY', 'manual'),

    // AvantPOS's own VAT status for its invoices (plan prices are VAT-inclusive).
    'vat_registered' => (bool) env('BILLING_VAT_REGISTERED', true),
    'vat_rate' => '12',

    // An invoice is due this many days after it is issued (renewals: on the period end).
    'invoice_due_days' => 7,

    // Renewal invoices and reminders go out this many days before the period ends.
    'renewal_notice_days' => 7,

    // Where the gateway sends the customer back after paying.
    'return_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/settings/billing',

    'gateways' => [
        'xendit' => [
            'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
            'secret_key' => env('XENDIT_SECRET_KEY'),
            // Sent by Xendit in the x-callback-token header of every webhook.
            'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        ],
        'paymongo' => [
            'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),
            'secret_key' => env('PAYMONGO_SECRET_KEY'),
            // Signs every webhook (Paymongo-Signature header, HMAC-SHA256).
            'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        ],
    ],

];
