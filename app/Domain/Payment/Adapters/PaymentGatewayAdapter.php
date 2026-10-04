<?php

namespace App\Domain\Payment\Adapters;

use App\Domain\Payment\Contracts\Charge;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Exceptions\GatewayException;

/**
 * Adapter Payment Gateway.
 *
 * Kenapa kelas ini ada (system_map §6): "Adapter eksternal menangani
 * gateway/kurir sehingga domain tidak bergantung pada bentuk payload vendor."
 * Modul Checkout TIDAK BOLEH tahu apa itu JSON Midtrans.
 *
 * ===================================================================
 *  CATATAN PENTING MENGENAI STATUS IMPLEMENTASI
 * ===================================================================
 * Lihat docs/KEPUTUSAN-TERBUKA.md OD-01 — vendor final (Midtrans atau Xendit)
 * BELUM diputuskan. Kelas ini sengaja ditulis lengkap secara struktur tapi
 * method `createTransaction()` masih melempar exception.
 *
 * ALASANNYA, dan ini bukan sekadar belum sempat:
 *   Saya tidak punya kredensial sandbox, dan menebak bentuk payload
 *   Midtrans tanpa bisa mengujinya berisiko menghasilkan adapter yang terlihat
 *   benar tapi salah di produksi. Itu lebih berbahaya daripada tidak ada kode.
 *
 * YANG SUDAH BENAR DAN TIDAK BOLEH DIUBAH:
 *   - Idempotency key memakai order_number (PRD §3.6).
 *   - Exception yang dilempar bisa dibaca CreateOrder untuk membatalkan order
 *     & melepas stok.
 *   - Tidak ada data kartu yang pernah melewati kelas ini (PRD §4.2).
 *
 * Cara menyelesaikan: isi `createTransaction()` sesuai dokumentasi API vendor
 * yang akhirnya dipilih, lalu jalankan test di bawah dengan sandbox kredensial.
 */
class PaymentGatewayAdapter implements PaymentGateway
{
    public function createTransaction(
        string $orderNumber,
        int $amount,
        string $method,
    ): Charge {
        // Sengaja belum diimplementasikan. Lihat catatan di atas.
        //
        // Apenas tulis kredensial tidak cukup: bentuk payload, kode status
        // sukses/gagal, dan cara membaca `expires_at` berbeda antar vendor.
        // Salah satu detail saja yang keliru akan menyebabkan reservasi stok
        // punya masa yang salah (lihat OD-03).
        throw new GatewayException(
            'Layanan pembayaran belum dikonfigurasi. Admin belum memilih penyedia pembayaran.',
            isTransient: false,
        );
    }

    /**
     * Status transaksi untuk reconciliation cron (PRD §6 edge case:
     * "Webhook payment gateway terlambat/gagal terkirim").
     *
     * Method ini juga belum diimplementasikan karena bergantung vendor. Yang
     * penting: cron WAJIB memperlakukan kegagalan di sini sebagai "tidak
     * diketahui", BUKAN "gagal" — jangan menandai order expired hanya karena
     * gateway tidak bisa dihubungi.
     */
    public function getTransactionStatus(string $transactionId): array
    {
        throw new GatewayException(
            'Layanan pembayaran belum dikonfigurasi.',
            isTransient: true,
        );
    }
}
