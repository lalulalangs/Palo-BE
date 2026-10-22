<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShippingQuoteRequest;
use App\Models\Cart;
use App\Models\User;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Http\JsonResponse;

class ShippingController extends Controller
{
    public function __construct(
        protected ShippingRateService $shippingRateService
    ) {}

    /**
     * Calculate multi-courier shipping fee quotes.
     */
    public function quote(ShippingQuoteRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $rawItems = $request->input('items');

        if (empty($rawItems) && $user) {
            $cart = Cart::with('items')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($cart && $cart->items->isNotEmpty()) {
                $rawItems = $cart->items->map(fn ($item) => [
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity,
                ])->toArray();
            }
        }

        $services = $this->shippingRateService->getQuotes(
            destinationCity: (string) $request->input('destination_city'),
            items: $rawItems ?? []
        );

        return $this->respond(['services' => $services]);
    }
}
