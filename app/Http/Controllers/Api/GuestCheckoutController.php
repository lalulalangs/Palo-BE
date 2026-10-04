<?php

namespace App\Http\Controllers\Api;

use App\Domain\Guest\Actions\CreateGuestCheckoutIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint checkout guest via WhatsApp.
 *
 * ===================================================================
 *  BACA DOKUMEN INI SEBELUM MENGUBAH APA PUN DI SINI
 * ===================================================================
 * PRD §3.11: "Saat guest menekan tombol checkout, sistem TIDAK memproses
 * pembayaran online — melainkan generate pesan pre-filled dan redirect ke
 * wa.me link CS."
 *
 *   "LOG ADALAH NIAT MENGHUBUNGKAN CS, BUKAN BUKTI PESAN TERKIRIM ATAU ORDER
 *    JADI; TIDAK MENGUNCI STOK."
 *
 * Karena itu controller ini TIDAK PERNAH memanggil CreateOrder, TIDAK
 * menyentuh stock_reservations, dan TIDAK memanggil payment gateway.
 * Yang dipanggil hanya CreateGuestCheckoutIntent.
 *
 * Kalau suatu saat endpoint ini dibuat mengembalikan `order_number`, itu
 * tanda alurnya salah dan melanggar PRD §7 yang menaruh "guest checkout online
 * langsung" di luar scope.
 */
class GuestCheckoutController extends Controller
{
    /**
     * POST /api/v1/guest/whatsapp-checkout
     */
    public function whatsappCheckout(Request $request, CreateGuestCheckoutIntent $action): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'items.required' => 'Keranjang tidak boleh kosong.',
            'items.min' => 'Keranjang tidak boleh kosong.',
        ]);

        $result = $action->fromCart(
            items: $validated['items'],
            guestName: $validated['guest_name'] ?? null,
            guestPhone: $validated['guest_phone'] ?? null,
            note: $validated['note'] ?? null,
        );

        return response()->json([
            'message' => 'Pesan WhatsApp siap dikirim.',
            'data' => [
                'reference_code' => $result['reference_code'],
                'wa_url' => $result['wa_url'],
                'message' => $result['message'],
                'estimated_total' => $result['log']->estimated_total,
                'estimated_total_display' => $result['log']->estimated_total > 0
                    ? null
                    : config('palorinjani.placeholders.price'),

                // WAJIB dikirim ke frontend. PRD §3.11: UI wajib menjelaskan
                // bahwa membuka WhatsApp bukan bukti order.
                'notice' => 'Harga dan stok final dikonfirmasi Customer Service. '
                    .'Pesan ini bukan bukti pesanan sudah tercatat.',
            ],
        ], 201);
    }

    /**
     * POST /api/v1/guest/custom-inquiry
     *
     * Permintaan pembelian partai / custom (PRD §6A).
     *
     * Tidak membuat cart, tidak mengarang harga custom, tidak mengunci stok.
     * Hanya menghasilkan pesan WA dengan konteks kebutuhan.
     */
    public function customInquiry(Request $request, CreateGuestCheckoutIntent $action): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.sku_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ], [
            'name.required' => 'Nama wajib diisi.',
            'phone.required' => 'Nomor WhatsApp wajib diisi.',
        ]);

        $result = $action->forCustomInquiry(
            guestName: $validated['name'],
            guestPhone: $validated['phone'],
            quantity: $validated['quantity'] ?? null,
            note: $validated['note'] ?? null,
            items: $validated['items'] ?? null,
        );

        return response()->json([
            'message' => 'Pesan WhatsApp siap dikirim.',
            'data' => [
                'reference_code' => $result['reference_code'],
                'wa_url' => $result['wa_url'],
                'message' => $result['message'],
                'notice' => 'Permintaan custom/partai ditangani manual oleh tim kami. '
                    .'Harga dan minimum order akan dikonfirmasi lewat WhatsApp.',
            ],
        ], 201);
    }
}
