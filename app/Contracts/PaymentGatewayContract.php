<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Kontrak integrasi payment gateway — BELUM DIIMPLEMENTASIKAN.
 *
 * Dokumen placeholder sampai vendor resmi (Midtrans/Xendit/lainnya) ditetapkan.
 * Jangan mendaftarkan binding implementasi untuk interface ini sebelum ada
 * gateway nyata; instruksi pembayaran saat ini dilayani secara manual.
 */
interface PaymentGatewayContract
{
    /**
     * Buat transaksi pembayaran untuk sebuah order.
     *
     * @return array{gateway: string, method: string, reference: string|null, instructions: array<string, mixed>}
     */
    public function createPayment(Order $order): array;

    /**
     * Verifikasi signature payload webhook dari gateway.
     */
    public function verifySignature(string $payload, string $signature): bool;

    /**
     * Urai payload webhook menjadi event netral.
     *
     * @return array{event_id: string, type: string, order_number: string, status: string}
     */
    public function parseWebhook(string $payload): array;
}
