<?php

namespace App\Domain\Payment\Contracts;

/**
 * Hasil pembuatan transaksi pembayaran di gateway.
 *
 * DTO immutable. Field dipilih hanya yang benar-benar dipakai sistem.
 *
 * PENTING: `expiresAt` WAJIB diisi dari batas bayar yang dikembalikan gateway,
 * bukan dari perhitungan sendiri. PRD §3.6:
 *   "Masa reservasi harus sama dengan batas bayar yang benar-benar berlaku di
 *    gateway untuk metode tersebut; tidak boleh memakai 15 menit jika
 *    instruksi pembayaran masih aktif 24 jam."
 */
final readonly class Charge
{
    /**
     * @param  string  $transactionId  ID transaksi dari gateway. Disimpan di
     *                                 `payments.gateway_transaction_id` yang
     *                                 UNIQUE — inilah kunci idempotensi.
     * @param  int  $amount  Nominal yang harus dibayar (rupiah).
     * @param  array<string, mixed>  $instructions  Instruksi untuk ditampilkan
     *                                              ke buyer (nomor VA, QR
     *                                              string, redirect URL).
     *                                              TIDAK BOLEH memuat data
     *                                              kartu — lihat PRD §4.2.
     * @param  \DateTimeInterface|null  $expiresAt  Batas bayar dari gateway.
     */
    public function __construct(
        public string $transactionId,
        public int $amount,
        public array $instructions = [],
        public ?\DateTimeInterface $expiresAt = null,
    ) {}
}
