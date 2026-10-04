<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Stok tidak cukup untuk memenuhi permintaan.
 *
 * Melempar exception ini HANYA dari ReserveStock, yang sudah berada di dalam
 * transaksi DB dengan baris SKU terkunci. Artinya pesan ini bisa dipercaya:
 * setelah exception dilempar, transaksi di-rollback dan TIDAK ADA order yang
 * terbentuk (PRD §6 AC: "buyer kedua menerima error 'stok telah habis'
 * SEBELUM order terbentuk").
 */
class InsufficientStockException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $skuId = null)
    {
        parent::__construct($message);
    }

    /**
     * SKU tidak ada, nonaktif, atau tidak bisa dipesan.
     *
     * Sengaja tidak menyebut SKU mana: jangan bocorkan katalog ke pihak yang
     * tidak berhak.
     */
    public static function skuUnavailable(): self
    {
        return new self('Salah satu produk di keranjang tidak tersedia.');
    }

    /**
     * Stok tidak mencukupi.
     *
     * PRD §3.4 AC: "Given stok SKU tersisa 3, When buyer mencoba set quantity
     * ke 5, Then sistem menolak dan menampilkan pesan 'Stok tersedia hanya 3'."
     * Karena itu pesan WAJIB menyebut jumlah yang tersedia.
     */
    public static function forSku(string $skuCode, int $requested, int $available): self
    {
        return new self(
            sprintf('Stok %s tidak mencukupi. Tersedia %d, diminta %d.', $skuCode, $available, $requested),
        );
    }
}
