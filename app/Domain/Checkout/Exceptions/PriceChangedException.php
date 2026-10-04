<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Harga berubah antara buyer membuka keranjang dan menekan "Bayar".
 *
 * PRD §3.6 AC:
 *   "Given harga produk berubah antara buyer membuka cart dan menekan 'Bayar',
 *    When checkout diproses, Then sistem menggunakan harga terkini dari server
 *    dan **menampilkan notifikasi perubahan harga ke buyer sebelum
 *    melanjutkan**."
 *
 * PENTING: harga di order TIDAK PERNAH memakai harga lama. Backend selalu
 * memakai harga server (PRD §3.12). Exception ini ada supaya buyer TAHU
 * bahwa totalnya berubah — supaya dia bisa memutuskan lanjut atau batal,
 * bukan diam-diam dikenai biaya yang lebih mahal.
 *
 * Response 409 + `changes[]` supaya frontend bisa menampilkan tabel
 * "harga lama → harga baru" sebelum mengonfirmasi.
 */
class PriceChangedException extends RuntimeException
{
    /**
     * @param  array<int, array{
     *     sku_id: int,
     *     sku_code: string,
     *     product_name: string,
     *     old_price: int,
     *     new_price: int,
     *     quantity: int
     * }>  $changes
     */
    private function __construct(
        string $message,
        public readonly array $changes,
        public readonly int $newGrandTotal,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, array{sku_id: int, sku_code: string, product_name: string, old_price: int, new_price: int, quantity: int}>  $changes
     */
    public static function make(array $changes, int $newGrandTotal): self
    {
        $count = count($changes);

        $message = $count === 1
            ? 'Harga 1 produk berubah sejak keranjang dibuka. Tinjau perubahannya sebelum melanjutkan.'
            : sprintf('Harga %d produk berubah sejak keranjang dibuka. Tinjau perubahannya sebelum melanjutkan.', $count);

        return new self($message, $changes, $newGrandTotal);
    }
}
