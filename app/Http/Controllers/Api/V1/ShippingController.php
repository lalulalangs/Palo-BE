<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShippingDestinationRequest;
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
     * Hitung tarif multi-kurir untuk satu tujuan.
     *
     * Server tidak pernah mempercayai nominal dari client; hanya
     * `destination_id`/`destination_city` dan isi keranjang yang dipakai.
     */
    public function quote(ShippingQuoteRequest $request): JsonResponse
    {
        $destinationCity = (string) $request->input('destination_city', '');
        $destinationId = $request->integer('destination_id') ?: null;

        if ($destinationId === null) {
            $destinationId = $this->shippingRateService->resolveDestinationId($destinationCity);
        }

        $quote = $this->shippingRateService->quote(
            destinationId: $destinationId,
            items: $this->resolveItems($request),
            destinationLabel: trim($destinationCity) !== '' ? $destinationCity : null,
        );

        return $this->respond($quote);
    }

    /**
     * Proxy pencarian destinasi RajaOngkir untuk pemilih alamat di frontend.
     */
    public function destinations(ShippingDestinationRequest $request): JsonResponse
    {
        return $this->respond([
            'destinations' => $this->shippingRateService->searchDestinations(
                keyword: (string) $request->input('search'),
                limit: (int) $request->input('limit', 10),
            ),
        ]);
    }

    /**
     * Item eksplisit dari request, atau isi keranjang user yang sedang login.
     *
     * @return array<int, array{sku_id: int, quantity: int}>
     */
    private function resolveItems(ShippingQuoteRequest $request): array
    {
        $rawItems = $request->input('items');

        if (! empty($rawItems)) {
            return array_map(fn (array $item): array => [
                'sku_id' => (int) $item['sku_id'],
                'quantity' => (int) $item['quantity'],
            ], $rawItems);
        }

        /** @var User|null $user */
        $user = $request->user('sanctum');

        if ($user === null) {
            return [];
        }

        $cart = Cart::with('items')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($cart === null || $cart->items->isEmpty()) {
            return [];
        }

        return $cart->items->map(fn ($item): array => [
            'sku_id' => (int) $item->sku_id,
            'quantity' => (int) $item->quantity,
        ])->all();
    }
}
