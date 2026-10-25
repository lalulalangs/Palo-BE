<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddToCartRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * Get active shopping cart items and summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->getCart($user, $guestToken);

        return $this->withGuestCartCookie($this->respond($cart), $cart);
    }

    /**
     * Add item to cart.
     */
    public function addItem(AddToCartRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->addItem($user, $guestToken, (int) $request->sku_id, (int) $request->quantity);

        return $this->withGuestCartCookie(
            $this->respond($cart, 'Produk berhasil ditambahkan ke keranjang.'),
            $cart,
        );
    }

    /**
     * Update item quantity in cart.
     */
    public function updateItem(UpdateCartItemRequest $request, int $skuId): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->updateItem($user, $guestToken, $skuId, (int) $request->quantity);

        return $this->withGuestCartCookie(
            $this->respond($cart, 'Jumlah barang keranjang berhasil diperbarui.'),
            $cart,
        );
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(Request $request, int $skuId): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->removeItem($user, $guestToken, $skuId);

        return $this->withGuestCartCookie(
            $this->respond($cart, 'Produk berhasil dihapus dari keranjang.'),
            $cart,
        );
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    private function withGuestCartCookie(JsonResponse $response, array $cart): JsonResponse
    {
        if (filled($cart['guest_token'] ?? null)) {
            $response->withCookie($this->guestCartCookie((string) $cart['guest_token']));
        }

        return $response;
    }
}
