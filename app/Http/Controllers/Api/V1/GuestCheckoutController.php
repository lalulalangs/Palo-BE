<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomInquiryRequest;
use App\Http\Requests\Api\V1\GuestCheckoutRequest;
use App\Http\Requests\Api\V1\GuestCodCheckoutRequest;
use App\Services\Cart\CartService;
use App\Services\Checkout\GuestCheckoutService;
use Illuminate\Http\JsonResponse;

class GuestCheckoutController extends Controller
{
    public function __construct(
        protected GuestCheckoutService $guestCheckoutService,
        protected CartService $cartService
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
            shippingCourier: $request->input('shipping_courier'),
            shippingService: $request->input('shipping_service'),
            shippingCost: (float) $request->input('shipping_cost', 0),
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

        return response()->json([
            'success' => true,
            'message' => 'Tautan WhatsApp pesanan berhasil dibuat.',
            'data' => $result,
        ], 201);
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

        return response()->json([
            'success' => true,
            'message' => 'Tautan WhatsApp konsultasi berhasil dibuat.',
            'data' => $result,
        ], 201);
    }
}
