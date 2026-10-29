<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Kontrak payment gateway. Implementasi aktif: DOKU Checkout (hosted page).
 *
 * Semua nominal yang dikirim ke gateway adalah Rupiah integer; konversi dari
 * kolom decimal dilakukan oleh implementasi.
 */
interface PaymentGatewayContract
{
    /**
     * Nama gateway yang disimpan pada kolom `payments.gateway`.
     */
    public function name(): string;

    /**
     * Apakah kredensial gateway sudah lengkap untuk memproses pembayaran.
     */
    public function isConfigured(): bool;

    /**
     * Buat transaksi pembayaran untuk sebuah order.
     *
     * `$paymentMethod` adalah metode pilihan customer (mis. `qris`,
     * `virtual_account`), bukan kolom `orders.payment_method` yang hanya
     * bernilai `online`/`cod`.
     *
     * @return array{
     *     gateway: string,
     *     method: string|null,
     *     reference: string,
     *     payment_url: string,
     *     expires_at: string|null,
     *     instructions: array<string, mixed>,
     *     raw_response: array<string, mixed>
     * }
     */
    public function createPayment(Order $order, ?string $paymentMethod = null): array;

    /**
     * Verifikasi signature notifikasi/webhook dari gateway.
     *
     * @param  array{client_id?: string, request_id?: string, request_timestamp?: string, signature?: string}  $headers
     * @param  string  $requestTarget  Path URL notifikasi yang terdaftar di gateway.
     */
    public function verifySignature(string $payload, array $headers, string $requestTarget): bool;

    /**
     * Urai payload notifikasi menjadi event netral.
     *
     * @return array{
     *     event_id: string,
     *     order_number: string,
     *     status: string,
     *     amount: int,
     *     channel: string|null,
     *     service: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function parseWebhook(string $payload): array;
}
