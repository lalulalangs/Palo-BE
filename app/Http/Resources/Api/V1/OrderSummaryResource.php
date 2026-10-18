<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Number;

/**
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusLabels = Order::getStatusLabels();
        $firstItem = $this->relationLoaded('items') ? $this->items->first() : null;
        $itemCount = $this->relationLoaded('items') ? $this->items->sum('quantity') : 0;

        return [
            'order_number' => $this->order_number,
            'status' => $this->getFrontendStatus(),
            'status_label' => $statusLabels[$this->status] ?? $this->status,
            'first_item_name' => $firstItem?->product_name_snapshot,
            'item_count' => $itemCount,
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_amount,
            'shipping_cost' => (float) $this->shipping_cost,
            'service_fee' => 0.0,
            'grand_total' => (float) $this->total,
            'grand_total_display' => Number::currency($this->total, 'IDR', 'id'),
            'voucher_code' => $this->voucher?->code,
            'payment_expires_at' => $this->stock_locked_until?->toIso8601String(),
            'needs_reconciliation' => $this->needsReconciliation(),
            'placed_at' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
