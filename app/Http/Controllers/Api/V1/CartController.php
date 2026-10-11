<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddToCartRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Models\User;
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

        $response = response()->json([
            'success' => true,
            'data' => $cart,
        ]);

        if (! $user && filled($cart['guest_token'])) {
            $response->withCookie(cookie('guest_cart_id', $cart['guest_token'], 60 * 24 * 30));
        }

        return $response;
    }

    /**
     * Add item to cart.
     */
    public function addItem(AddToCartRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->addItem($user, $guestToken, (int) $request->sku_id, (int) $request->quantity);

        $response = response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan ke keranjang.',
            'data' => $cart,
        ]);

        if (! $user && filled($cart['guest_token'])) {
            $response->withCookie(cookie('guest_cart_id', $cart['guest_token'], 60 * 24 * 30));
        }

        return $response;
    }

    /**
     * Update item quantity in cart.
     */
    public function updateItem(UpdateCartItemRequest $request, int $skuId): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->updateItem($user, $guestToken, $skuId, (int) $request->quantity);

        return response()->json([
            'success' => true,
            'message' => 'Jumlah barang keranjang berhasil diperbarui.',
            'data' => $cart,
        ]);
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(Request $request, int $skuId): JsonResponse
    {
        $user = $request->user('sanctum');
        $guestToken = $this->cartService->resolveGuestToken($request);
        $cart = $this->cartService->removeItem($user, $guestToken, $skuId);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus dari keranjang.',
            'data' => $cart,
        ]);
    }

    /**
     * Merge guest cart into authenticated user cart.
     */
    public function merge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $guestToken = $request->input('guest_token')
            ?? $request->header('X-Guest-Token')
            ?? $request->cookie('guest_cart_id')
            ?? $request->cookie('guest_token');

        if (! $guestToken) {
            return response()->json([
                'success' => true,
                'data' => $this->cartService->getCart($user),
            ]);
        }

        $cart = $this->cartService->mergeGuestCart($user, (string) $guestToken);

        return response()->json([
            'success' => true,
            'message' => 'Keranjang tamu berhasil digabungkan ke akun Anda.',
            'data' => $cart,
        ]);
    }
}
