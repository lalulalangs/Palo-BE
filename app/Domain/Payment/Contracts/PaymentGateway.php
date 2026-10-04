<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Exceptions\GatewayException;

/**
 * Kontrak payment gateway.
 *
 * Kenapa interface (system_map §6): "Adapter eksternal menangani gateway/kurir
 * sehingga domain tidak bergantung pada bentuk payload vendor." Modul Checkout
 * tidak boleh tahu apa itu JSON Midtrans.
 *
 * Lihat docs/KEPUTUSAN-TERBUKA.md OD-01 — vendor final belum diputuskan antara
 * Midtrans dan Xendit. Karena itu JANGAN sampai nama vendor bocor ke modul
 * Checkout/Payment. Yang boleh tahu vendor hanya lapisan Adapter.
 */
interface PaymentGateway
{
    /**
     * Buat transaksi pembayaran.
     *
     * WAJIB memakai $orderNumber sebagai idempotency key agar pemanggilan
     * ulang tidak membuat dua transaksi di sisi gateway (PRD §3.6).
     *
     * @param  string  $orderNumber  Dipakai sebagai idempotency key.
     * @param  int  $amount  Nominal dalam rupiah.
     * @param  string  $method  virtual_account | ewallet | qris | credit_card
     *
     * @throws GatewayException When the gateway rejects the request or is
     *                          unreachable. CreateOrder akan membatalkan order
     *                          dan melepas stok bila ini terjadi.
     */
    public function createTransaction(
        string $orderNumber,
        int $amount,
        string $method,
    ): Charge;

    /**
     * Ambil status transaksi dari gateway (dipakai reconciliation cron).
     *
     * Mengembalikan array status gateway, mis. ['status' => 'settlement',
     * 'amount' => 275000].
     *
     * @throws GatewayException
     */
    public function getTransactionStatus(string $transactionId): array;
}
