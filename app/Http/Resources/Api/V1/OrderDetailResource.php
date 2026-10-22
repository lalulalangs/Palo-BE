<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * @mixin Order
 */
class OrderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $firstItem = $this->relationLoaded('items') ? $this->items->first() : null;
        $itemCount = $this->relationLoaded('items') ? $this->items->sum('quantity') : 0;

        $items = $this->relationLoaded('items') ? $this->items->map(function (OrderItem $item) {
            $thumbnailUrl = null;
            if ($item->relationLoaded('sku') && $item->sku) {
                $sku = $item->sku;
                if ($sku->relationLoaded('product') && $sku->product) {
                    $product = $sku->product;
                    if ($product->thumbnail) {
                        $thumbnailUrl = $product->thumbnail->url;
                    } elseif ($product->media && $product->media->isNotEmpty()) {
                        $thumbnailUrl = $product->media->first()->url;
                    }
                }
            }

            if ($thumbnailUrl && ! Str::startsWith($thumbnailUrl, ['http://', 'https://'])) {
                $thumbnailUrl = Storage::disk('public')->url($thumbnailUrl);
            }

            return [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'product_name' => $item->product_name_snapshot,
                'product_slug' => $item->sku?->product?->slug,
                'variant_name' => $item->variant_name_snapshot,
                'sku_code' => $item->sku_code_snapshot,
                'unit_price' => (float) $item->price_snapshot,
                'unit_price_display' => Number::currency($item->price_snapshot, 'IDR', 'id'),
                'quantity' => $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'subtotal_display' => Number::currency($item->subtotal, 'IDR', 'id'),
                'image' => $thumbnailUrl,
            ];
        })->values()->toArray() : [];

        $history = $this->relationLoaded('statusHistories') ? $this->statusHistories->map(function (OrderStatusHistory $entry) {
            return [
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'actor_type' => $entry->changed_by_admin_id ? 'admin' : 'customer',
                'note' => $entry->note,
                'occurred_at' => $entry->created_at?->toIso8601String(),
            ];
        })->values()->toArray() : [];

        $paymentData = null;
        if ($this->relationLoaded('payment') && $this->payment) {
            $paymentData = [
                'method' => $this->payment->payment_method,
                'amount' => (float) $this->payment->amount,
                'status' => $this->payment->status,
                'instructions' => $this->payment->raw_response,
            ];
        }

        return [
            'order_number' => $this->order_number,
            'status' => $this->getFrontendStatus(),
            'status_label' => $this->status->label(),
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
            'items' => $items,
            'history' => $history,
            'status_histories' => $history,
            'shipping_address' => $this->shippingAddressData(),
            'shipping_courier' => $this->shipping_courier,
            'shipping_service' => $this->shipping_service,
            'tracking_number' => $this->tracking_number,
            'payment_method' => $this->payment_method ?? $this->payment?->payment_method,
            'is_cod' => $this->isCod(),
            'cod_reference_code' => $this->cod_reference_code,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'refund_status' => $this->refund_status,
            'payment' => $paymentData,
        ];
    }
}
