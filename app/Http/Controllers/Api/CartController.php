<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cart\Actions\AddCartItem;
use App\Domain\Cart\Actions\RemoveCartItem;
use App\Domain\Cart\Actions\UpdateCartItem;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Http\Resources\CartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint keranjang.
 *
 * MENANGANI DUA JENAKAKUN SECARA BERSAMAAN (PRD §3.4):
 *   - Guest  : cart disimpan di Redis per sesi. Tidak butuh login.
 *   - User   : cart disimpan di PostgreSQL. Butuh token Sanctum.
 *
 * Endpoint ini SENGAJA tidak memakai middleware auth:sanctum, supaya guest
 * bisa berbelan tanpa akun (PRD §3.11 & §1.2 "menurunkan friksi bagi pengguna
 * yang belum siap membuat akun").
 *
 * Pemilihan cart ditangani CartResolver.
 */
class CartController extends Controller
{
    /**
     * GET /api/v1/cart
     */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->resolveCart($request);

        return response()->json([
            'data' => (new CartResource($cart))->toArray($request),
        ]);
    }

    /**
     * POST /api/v1/cart/items
     */
    public function store(Request $request, AddCartItem $addItem): JsonResponse
    {
        $validated = $request->validate([
            'sku_id' => ['required', 'integer', 'exists:skus,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'sku_id.exists' => 'Produk tidak tersedia.',
            'quantity.min' => 'Jumlah minimal 1.',
        ]);

        $cart = $this->resolveCart($request);

        try {
            $addItem->execute($cart, $validated['sku_id'], $validated['quantity']);
        } catch (InsufficientStockException $e) {
            // PRD §3.4: keranjang TIDAK mengunci stok, tapi jumlah yang diminta
            // tidak boleh melebihi stok saat itu juga.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['quantity' => [$e->getMessage()]],
            ], 409);
        }

        $cart->refresh();

        return response()->json([
            'message' => 'Produk ditambahkan ke keranjang.',
            'data' => (new CartResource($cart))->toArray($request),
        ], 201);
    }

    /**
     * PATCH /api/v1/cart/items/{skuId}
     */
    public function update(Request $request, UpdateCartItem $updateItem, int $skuId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ], [
            'quantity.min' => 'Jumlah minimal 0. Gunakan 0 atau hapus untuk mengeluarkan item.',
        ]);

        $cart = $this->resolveCart($request);

        // quantity = 0 berarti keluarkan, bukan set 0 lalu simpan.
        if ((int) $validated['quantity'] === 0) {
            $updateItem->remove($cart, $skuId);
        } else {
            try {
                $updateItem->execute($cart, $skuId, (int) $validated['quantity']);
            } catch (InsufficientStockException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['quantity' => [$e->getMessage()]],
                ], 409);
            }
        }

        $cart->refresh();

        return response()->json([
            'message' => 'Keranjang diperbarui.',
            'data' => (new CartResource($cart))->toArray($request),
        ]);
    }

    /**
     * DELETE /api/v1/cart/items/{skuId}
     */
    public function destroy(Request $request, RemoveCartItem $removeItem, int $skuId): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $removeItem->execute($cart, $skuId);
        $cart->refresh();

        return response()->json([
            'message' => 'Produk dikeluarkan dari keranjang.',
            'data' => (new CartResource($cart))->toArray($request),
        ]);
    }

    /**
     * Tentukan cart mana yang dipakai untuk request ini.
     *
     * Penting: session_id untuk guest diambil dari cookie sesi yang sudah
     * di-set Laravel. Tanpa ini, setiap request guest akan membuat cart baru.
     */
    private function resolveCart(Request $request): Cart
    {
        return app(CartResolver::class)->for($request);
    }
}
