<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutOrderRequest;
use App\Http\Resources\Api\V1\OrderDetailResource;
use App\Http\Resources\Api\V1\OrderSummaryResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Checkout\RegisteredCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;

class CheckoutController extends Controller
{
    public function __construct(
        protected RegisteredCheckoutService $registeredCheckoutService
    ) {}

    /**
     * Create formal order for registered customer with atomic stock locking (PRD §3.6).
     */
    public function createOrder(CheckoutOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $order = $this->registeredCheckoutService->process(
            user: $user,
            shippingAddress: (array) $request->input('shipping_address'),
            itemsInput: $request->input('items'),
            shippingCourier: (string) $request->input('shipping_courier'),
            shippingService: (string) $request->input('shipping_service'),
            shippingCost: (float) $request->input('shipping_cost'),
            shippingEtd: $request->input('shipping_etd'),
            paymentMethod: (string) $request->input('payment_method'),
            voucherCode: $request->input('voucher_code'),
            acknowledgePriceChange: (bool) $request->input('acknowledge_price_change', false)
        );

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibuat.',
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->getFrontendStatus(),
                'subtotal' => (float) $order->subtotal,
                'discount_total' => (float) $order->discount_amount,
                'shipping_cost' => (float) $order->shipping_cost,
                'grand_total' => (float) $order->total,
                'grand_total_display' => Number::currency($order->total, 'IDR', 'id'),
                'payment_expires_at' => $order->stock_locked_until?->toIso8601String(),
                'payment' => [
                    'method' => $order->payment?->payment_method,
                    'instructions' => $order->payment?->raw_response,
                ],
            ],
        ], 201);
    }

    /**
     * List registered user's order history.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->with(['items', 'voucher'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => OrderSummaryResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get snapshot details of a specific order belonging to the user.
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('order_number', $orderNumber)
            ->with(['items.sku.product.thumbnail', 'payment', 'voucher', 'statusHistories'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new OrderDetailResource($order),
        ]);
    }
}
