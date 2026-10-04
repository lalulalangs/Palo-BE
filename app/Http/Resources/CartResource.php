<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keranjang untuk API.
 *
 * PENTING: `available_stock` dihitung dari on_hand dikurangi reservasi aktif.
 * Ini yang membuat UI bisa menampilkan "tersisa 2" tanpa misleading.
 *
 * `unit_price` yang dikirim adalah harga SAAT INI dari database, bukan
 * `price_snapshot` yang disimpan. Alasannya: kalau admin mengubah harga,
 * keranjang harus langsung menampilkan harga baru — tidak menunggu update
 * di sisi client (PRD §3.2 AC: "harga baru tampil tanpa cache lama").
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items->map(function ($item) {
            $sku = $item->sku;
            $available = $sku?->availableQuantity() ?? 0;
            $unitPrice = (int) ($sku?->price ?? 0);
            $subtotal = $unitPrice * $item->quantity;

            return [
                'sku_id' => $item->sku_id,
                'sku_code' => $sku?->code ?? config('palorinjani.placeholders.sku'),
                'product_name' => $sku?->product?->name ?? config('palorinjani.placeholders.product_name'),
                'product_slug' => $sku?->product?->slug,
                'variant_name' => $sku?->variant?->name,
                'unit_price' => $unitPrice,
                'unit_price_display' => $unitPrice > 0 ? null : config('palorinjani.placeholders.price'),
                'quantity' => $item->quantity,
                'subtotal' => $subtotal,

                // PENTING: frontend memakai ini untuk membatasi stepper.
                'available_stock' => $available,
                'is_out_of_stock' => $available <= 0,
                'exceeds_stock' => $item->quantity > $available,

                'image' => $sku?->product?->media()->first()?->path,
            ];
        })->values();

        $subtotal = (int) $items->sum('subtotal');

        return [
            'items' => $items,
            'summary' => [
                'item_count' => (int) $this->items->sum('quantity'),
                'subtotal' => $subtotal,
                'subtotal_display' => $subtotal > 0 ? null : config('palorinjani.placeholders.price'),

                // Notifikasi kalau ada item yang melebihi stok. PRD §3.4 AC:
                // user harus diberi tahu sebelum checkout gagal.
                'has_stock_warning' => $items->contains('exceeds_stock', true) || $items->contains('is_out_of_stock', true),
            ],
        ];
    }
}
