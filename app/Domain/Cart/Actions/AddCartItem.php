<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Exceptions\InsufficientStockException;

/**
 * Menambah item ke keranjang.
 *
 * PRD §3.4: "Saat item ditambahkan, sistem melakukan validasi stok real-time
 * terhadap SKU."
 *
 * PENTING (PRD §3.4): "Cart TIDAK melakukan reservasi stok permanen."
 * Action ini HANYA MEMBACA ketersediaan, tidak pernah membuat baris di
 * stock_reservations. Kalau keranjang mengunci stok, satu abandonment cart
 * akan membekukan stok selamanya.
 */
class AddCartItem
{
    public function execute(Cart $cart, int $skuId, int $quantity): void
    {
        $sku = Sku::whereKey($skuId)->where('is_active', true)->first();

        if ($sku === null) {
            throw InsufficientStockException::skuUnavailable();
        }

        // Kalau item sudah ada, quantity BARU = lama + baru, dan validasi stok
        // dilakukan terhadap total tersebut (bukan terhadap selisihnya).
        $existing = $cart->items()->where('sku_id', $skuId)->first();
        $newQuantity = ((int) ($existing->quantity ?? 0)) + $quantity;

        // Validasi real-time (PRD §3.4 AC: "Given stok SKU tersisa 3, When
        // buyer mencoba set quantity ke 5, Then sistem menolak dan
        // menampilkan pesan 'Stok tersedia hanya 3'").
        $available = $sku->availableQuantity();

        if ($newQuantity > $available) {
            throw InsufficientStockException::forSku($sku->code, $newQuantity, $available);
        }

        // price_snapshot hanya untuk tampilan. Total dihitung ulang dari
        // skus.price saat checkout (PRD §3.12).
        $cart->items()->updateOrCreate(
            ['sku_id' => $skuId],
            ['quantity' => $newQuantity, 'price_snapshot' => (int) $sku->price],
        );
    }
}
