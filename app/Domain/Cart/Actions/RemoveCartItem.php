<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;

/**
 * Mengeluarkan item dari keranjang.
 */
class RemoveCartItem
{
    public function execute(Cart $cart, int $skuId): void
    {
        $cart->items()->where('sku_id', $skuId)->delete();
    }
}
