<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/**
 * Bantuan tes untuk integrasi DOKU Checkout: konfigurasi sandbox palsu dan
 * fake endpoint pembuatan pembayaran yang menggemakan invoice_number order.
 */
trait FakesDokuCheckout
{
    protected function configureDokuForTests(): void
    {
        config([
            'doku.base_url' => 'https://api-sandbox.doku.com',
            'doku.client_id' => 'MCH-TEST-0001',
            'doku.secret_key' => 'SK-TEST-secret',
            'doku.payment_window_minutes' => 60,
            'doku.restrict_payment_methods' => true,
            'doku.notification_path' => '/api/v1/payments/webhook/doku',
        ]);
    }

    protected function fakeDokuCheckout(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => function (ClientRequest $request) {
                $payload = json_decode($request->body(), true);
                $invoiceNumber = (string) ($payload['order']['invoice_number'] ?? 'INV-UNKNOWN');

                return Http::response([
                    'message' => ['SUCCESS'],
                    'response' => [
                        'order' => [
                            'invoice_number' => $invoiceNumber,
                            'amount' => (string) ($payload['order']['amount'] ?? 0),
                            'session_id' => 'session-'.$invoiceNumber,
                        ],
                        'payment' => [
                            'payment_due_date' => $payload['payment']['payment_due_date'] ?? 60,
                            'token_id' => 'token-'.$invoiceNumber,
                            'url' => 'https://sandbox.doku.com/checkout/link/'.$invoiceNumber,
                            'expired_date' => '20991231235959',
                        ],
                    ],
                ]);
            },
        ]);
    }
}
