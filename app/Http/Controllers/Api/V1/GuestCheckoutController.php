<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomInquiryRequest;
use App\Http\Requests\Api\V1\GuestCancelOrderRequest;
use App\Http\Requests\Api\V1\GuestCheckoutRequest;
use App\Http\Requests\Api\V1\GuestCodCheckoutRequest;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Checkout\GuestCheckoutService;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Http\JsonResponse;

class GuestCheckoutController extends Controller
{
    public function __construct(
        protected GuestCheckoutService $guestCheckoutService,
        protected CartService $cartService,
        protected OrderTransitionService $orderTransitionService
    ) {}

    /**
     * Create formal COD order for guest customer with WhatsApp redirect link.
     */
    public function codCheckout(GuestCodCheckoutRequest $request): JsonResponse
    {
        $sessionId = $this->cartService->resolveGuestToken($request);

        $result = $this->guestCheckoutService->codCheckout(
            shippingAddress: (array) $request->input('shipping_address'),
            itemsInput: $request->input('items'),
            note: $request->input('note'),
            sessionId: $sessionId
        );

        return response()->json([
            'success' => true,
            'message' => 'Pesanan COD berhasil dibuat.',
            'data' => $result,
        ], 201);
    }

    /**
     * Generate WhatsApp order message and audit log for guest checkout (PRD §3.11).
     */
    public function whatsappCheckout(GuestCheckoutRequest $request): JsonResponse
    {
        $sessionId = $this->cartService->resolveGuestToken($request);

        $result = $this->guestCheckoutService->checkout(
            itemsInput: $request->input('items'),
            guestName: $request->input('guest_name'),
            guestPhone: $request->input('guest_phone'),
            note: $request->input('note'),
            sessionId: $sessionId
        );

        return $this->respond($result, 'Tautan WhatsApp pesanan berhasil dibuat.', status: 201);
    }

    /**
     * Submit custom wholesale / expedition inquiry via WhatsApp (PRD §6A).
     */
    public function customInquiry(CustomInquiryRequest $request): JsonResponse
    {
        $sessionId = $this->cartService->resolveGuestToken($request);

        $result = $this->guestCheckoutService->customInquiry(
            name: (string) $request->input('name'),
            phone: (string) $request->input('phone'),
            quantity: $request->filled('quantity') ? (int) $request->input('quantity') : null,
            note: $request->input('note'),
            itemsInput: $request->input('items'),
            sessionId: $sessionId
        );

        return $this->respond($result, 'Tautan WhatsApp konsultasi berhasil dibuat.', status: 201);
    }

    /**
     * Cancel unconfirmed WhatsApp guest order upon buyer's request.
     */
    public function cancelOrder(GuestCancelOrderRequest $request): JsonResponse
    {
        $orderNumber = (string) $request->input('order_number');
        $referenceCode = (string) $request->input('reference_code');
        $reason = (string) ($request->input('reason') ?: 'Dibatalkan oleh pembeli');

        /** @var Order|null $order */
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        // Pastikan metode pesanan adalah WhatsApp
        if ($order->payment_method !== Order::PAYMENT_METHOD_WHATSAPP) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pesanan WhatsApp yang dapat dibatalkan melalui jalur ini.',
            ], 422);
        }

        // Pastikan status masih menunggu konfirmasi
        $statusValue = $order->status instanceof OrderStatus ? $order->status->value : $order->status;
        if ($statusValue !== Order::STATUS_AWAITING_CONFIRMATION) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak dalam status menunggu konfirmasi sehingga tidak dapat dibatalkan.',
            ], 422);
        }

        // Verifikasi kode referensi (shared secret kepemilikan tamu)
        if ($order->guest_reference_code !== $referenceCode) {
            return response()->json([
                'success' => false,
                'message' => 'Kode referensi pesanan tidak sesuai.',
            ], 403);
        }

        // Eksekusi pembatalan status dan pemulihan pembayaran
        $this->orderTransitionService->cancelOrder($order, $reason, actorId: null);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibatalkan.',
        ], 200);
    }
}
