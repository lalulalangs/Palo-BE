<?php

namespace App\Services\Payment\Doku;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DokuCheckoutClient
{
    private const TARGET = '/checkout/v1/payment';

    public function __construct(private DokuSignature $signature) {}

    public function createPayment(string $invoiceNumber, int $amount, string $callbackUrl): array
    {
        $body = [
            'order' => [
                'amount' => $amount,
                'invoice_number' => $invoiceNumber,
                'callback_url' => $callbackUrl,
                'auto_redirect' => true,
            ],
            'payment' => [
                'payment_due_date' => config('doku.due_minutes'),
            ],
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z'); // TODO verifikasi apakah perlu dirubah jadi UTC+7 atau UTC+8

        $response = Http::timeout(15)
            ->withHeaders([
                'Client-Id' => config('doku.client_id'),
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => $this->signature->make($requestId, $timestamp, self::TARGET, $rawBody),
            ])
            ->withBody($rawBody, 'application/json')
            ->post(config('doku.base_url').self::TARGET);

        if (! $response->successful()) {
            logger()->warning('DOKU create payment failed', [
                'invoice' => $invoiceNumber,
                'status' => $response->status(),
                'errors' => $response->json('error_messages'),
            ]);
            throw new RuntimeException('Gagal membuat pembayaran DOKU.');
        }

        $data = $response->json('response');

        return [
            'url' => $data['payment']['url'],
            'token_id' => $data['payment']['token_id'],
            'session_id' => $data['order']['session_id'] ?? null,
            'expired_datetime' => $data['payment']['expired_datetime'] ?? null, // UTC
            'raw' => $response->json(),
        ];
    }
}
