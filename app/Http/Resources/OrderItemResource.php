<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris item order untuk API.
 *
 * SEMUA nilai di sini dibaca dari kolom snapshot, bukan dari katalog.
 * Lihat docblock OrderResource untuk alasannya (PRD §3.5 AC).
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sku_id' => $this->sku_id,
            'product_name' => $this->product_name,
            'product_slug' => $this->product_slug,
            'variant_name' => $this->displayVariant(),
            'sku_code' => $this->sku_code,
            'attributes' => $this->attributes,
            'attribute_summary' => $this->attributeSummary(),
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'subtotal' => $this->subtotal,
            'image' => $this->image_path,
        ];
    }
}
