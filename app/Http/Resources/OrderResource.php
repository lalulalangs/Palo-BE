<?php

namespace App\Http\Resources;

use App\Domain\Checkout\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi order untuk API.
 *
 * PENTING: semua data produk di sini dibaca dari SNAPSHOT (order_items),
 * BUKAN dari relasi ke katalog. PRD §3.5 AC:
 *   "Given pelanggan membuka riwayat pesanan lama, When produk terkait sudah
 *    dihapus dari katalog, Then detail pesanan tetap tampil lengkap (dari
 *    snapshot, bukan referensi live)."
 *
 * Itu sebabnya OrderResource TIDAK pernah memanggil $order->items->load('sku.product').
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),

            // Ringkasan untuk kartu di daftar pesanan.
            'item_count' => (int) $this->items->sum('quantity'),
            'first_item_name' => $this->items->first()?->product_name,

            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_cost' => $this->shipping_cost,
            'service_fee' => $this->service_fee,
            'grand_total' => $this->grand_total,

            // Tampilkan placeholder kalau harga belum diisi admin
            // (PRD §1.1A) supaya UI tidak mengarang angka.
            'grand_total_display' => $this->grand_total > 0
                ? null
                : config('palorinjani.placeholders.price'),

            'voucher_code' => $this->voucher_code,
            'shipping_address' => $this->shipping_address,
            'shipping_courier' => $this->shipping_courier,
            'shipping_service' => $this->shipping_service,
            'shipping_etd' => $this->shipping_etd,
            'tracking_number' => $this->tracking_number,

            'payment_expires_at' => $this->payment_expires_at?->toIso8601String(),
            'needs_reconciliation' => $this->needs_reconciliation,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            // Timeline untuk halaman lacak (PRD §3.9 AC: riwayat perubahan
            // lengkap dengan timestamp).
            'status_histories' => $this->whenLoaded(
                $this->statusHistories,
                fn () => $this->statusHistories->map(fn ($h) => [
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'actor_type' => $h->actor_type->value,
                    'note' => $h->note,
                    'occurred_at' => $h->created_at->toIso8601String(),
                ])->values(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Label Bahasa Indonesia untuk status (bukan string enum mentah).
     */
    private function statusLabel(): string
    {
        return match ($this->status->value) {
            'menunggu_pembayaran' => 'Menunggu Pembayaran',
            'dibayar' => 'Dibayar',
            'diproses' => 'Diproses',
            'dikirim' => 'Dikirim',
            'selesai' => 'Selesai',
            'expired' => 'Kadaluarsa',
            'dibatalkan' => 'Dibatalkan',
            default => $this->status->value,
        };
    }
}
