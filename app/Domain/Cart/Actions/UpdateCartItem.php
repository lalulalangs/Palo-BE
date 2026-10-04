<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Exceptions\InsufficientStockException;

/**
 * Mengubah jumlah item di keranjang.
 */
class UpdateCartItem
{
    public function execute(Cart $cart, int $skuId, int $quantity): void
    {
        $item = $cart->items()->where('sku_id', $skuId)->first();

        if ($item === null) {
            return; // bukan error: user memang sudah menghapus itemnya
        }

        $sku = Sku::whereKey($skuId)->where('is_active', true)->first();

        if ($sku === null) {
            // SKU sudah nonaktif / dihapus.
            // PRD §3.4 AC: "Given item di cart sudah dihapus dari katalog oleh
            // Admin, When buyer membuka halaman cart, Then item tersebut
            // otomatis dihapus/diberi notifikasi".
            $item->delete();

            return;
        }

        $available = $sku->availableQuantity();

        if ($quantity > $available) {
            throw InsufficientStockException::forSku($sku->code, $quantity, $available);
        }

        $item->update([
            'quantity' => $quantity,
            // Segarkan snapshot harga supaya UI tidak menampilkan harga lama.
            'price_snapshot' => (int) $sku->price,
        ]);
    }

    /**
     * Keluarkan item (dipakai saat quantity di-set 0).
     */
    public function remove(Cart $cart, int $skuId): void
    {
        $cart->items()->where('sku_id', $skuId)->delete();
    }
}
