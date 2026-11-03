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
use Illuminate\Http\Request;

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
            sessionId: $sessionId,
            courier: $request->input('courier')
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
            sessionId: $sessionId,
            shippingAddress: $request->input('shipping_address'),
            courier: $request->input('courier'),
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
     * Get real-time status and metadata of guest WhatsApp order.
     */
    public function showOrderStatus(Request $request, string $orderNumber): JsonResponse
    {
        $referenceCode = (string) $request->query('reference_code');

        /** @var Order|null $order */
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->whereNotNull('guest_reference_code')
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        // Validasi kepemilikan kode referensi tamu (constant-time comparison)
        if ($referenceCode === '' || ! hash_equals((string) $order->guest_reference_code, $referenceCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode referensi pesanan tidak sesuai.',
            ], 403);
        }

        $statusValue = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;
        $canCancel = $statusValue === Order::STATUS_AWAITING_CONFIRMATION && $order->payment_method === Order::PAYMENT_METHOD_WHATSAPP;

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'reference_code' => $order->guest_reference_code,
                'status' => $statusValue,
                'status_label' => OrderStatus::labelFor($order->status),
                'can_cancel' => $canCancel,
                'payment_method' => $order->payment_method,
                'total' => (float) $order->total,
                'created_at' => $order->created_at?->toIso8601String(),
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            ],
        ], 200);
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
            ->whereNotNull('guest_reference_code')
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        // 1. Prioritas Otorisasi: Verifikasi kode referensi tamu SEBELUM cek status pesanan (constant-time comparison)
        if ($referenceCode === '' || ! hash_equals((string) $order->guest_reference_code, $referenceCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode referensi pesanan tidak sesuai.',
            ], 403);
        }

        // Pastikan metode pesanan adalah WhatsApp
        if ($order->payment_method !== Order::PAYMENT_METHOD_WHATSAPP) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pesanan WhatsApp yang dapat dibatalkan melalui jalur ini.',
            ], 422);
        }

        $statusValue = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        // 2. Idempotensi: Jika sudah dalam keadaan dibatalkan sebelumnya
        if ($statusValue === Order::STATUS_CANCELLED) {
            return response()->json([
                'success' => true,
                'message' => 'Pesanan sudah dalam keadaan dibatalkan sebelumnya.',
                'data' => [
                    'status' => 'cancelled',
                    'can_cancel' => false,
                ],
            ], 200);
        }

        // 3. Status selain awaiting_confirmation (misal processing, shipped, completed)
        if ($statusValue !== Order::STATUS_AWAITING_CONFIRMATION) {
            return response()->json([
                'success' => false,
                'code' => 'ORDER_ALREADY_PROCESSED',
                'error_code' => 'ORDER_ALREADY_PROCESSED',
                'message' => 'Pesanan tidak dalam status menunggu konfirmasi sehingga tidak dapat dibatalkan.',
                'data' => [
                    'status' => $statusValue,
                    'can_cancel' => false,
                ],
            ], 422);
        }

        // Eksekusi pembatalan status dan pemulihan pembayaran
        $this->orderTransitionService->cancelOrder($order, $reason, actorId: null);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibatalkan.',
        ], 200);
    }
}
