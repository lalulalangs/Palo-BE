<?php

namespace App\Http\Controllers\Api;

use App\Domain\Checkout\Actions\CreateOrder;
use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Exceptions\InvalidStatusTransitionException;
use App\Domain\Checkout\Exceptions\PriceChangedException;
use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Services\CourierUnavailableException;
use App\Domain\Checkout\Services\ShippingQuoteNotFoundException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Payment\Exceptions\GatewayException;
use App\Domain\Voucher\Exceptions\VoucherQuotaExhaustedException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint order untuk pelanggan terdaftar.
 *
 * CATATAN ARSITEKTUR: controller ini TIDAK melakukan query transaksi bisnis
 * langsung. Semua logika ada di Action di Domain. Yang dilakukan di sini
 * hanya: baca input -> panggil action -> bentuk response.
 * system_map §6: "Controller tidak melakukan SQL transaksi bisnis langsung;
 * panggil action/domain service."
 */
class OrderController extends Controller
{
    /**
     * Buat order baru.
     *
     * POST /api/v1/checkout/orders
     */
    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        try {
            $order = $createOrder->execute(
                user: $request->user(),
                data: $request->payload(),
            );

            $order->load(['items', 'payments', 'statusHistories']);

            return response()->json([
                'message' => 'Pesanan berhasil dibuat.',
                'data' => (new OrderResource($order))->toArray($request),
            ], 201);
        } catch (PriceChangedException $e) {
            // PRD §3.6 AC. PENTING: TIDAK ada order yang terbentuk — exception
            // dilempar dari dalam transaksi, jadi semuanya sudah rollback.
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'price_changed',
                'changes' => $e->changes,
                'new_grand_total' => $e->newGrandTotal,
            ], 409);

        } catch (InsufficientStockException $e) {
            // 409 Conflict: stok berubah setelah buyer melihat halaman.
            // Ini kondisi NORMAL di e-commerce, bukan error server.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['stock' => [$e->getMessage()]],
            ], 409);

        } catch (VoucherQuotaExhaustedException|VoucherNotApplicableException $e) {
            // 422: input valid secara bentuk, tapi aturannya tidak terpenuhi.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['voucher_code' => $e->reasons ?: [$e->getMessage()]],
            ], 422);

        } catch (ShippingQuoteNotFoundException $e) {
            // 409: quote ongkir hilang dari cache. Buyer harus hitung ulang.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['shipping_courier' => [$e->getMessage()]],
            ], 409);

        } catch (CourierUnavailableException $e) {
            // 503: kurir down. WAJIB memblokir checkout (PRD §3.8 AC) —
            // JANGAN Ubah jadi 200 dengan ongkir fallback.
            Log::warning('Checkout diblokir karena API kurir bermasalah', [
                'user_id' => $request->user()?->getKey(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['shipping_courier' => [$e->getMessage()]],
            ], 503);

        } catch (GatewayException $e) {
            // 502: pembayaran gagal dibuat. Order sudah dibatalkan & stok
            // dilepas oleh CreateOrder::handleGatewayFailure().
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['payment_method' => [$e->getMessage()]],
            ], 502);
        }
    }

    /**
     * Riwayat pesanan milik user yang sedang login.
     *
     * GET /api/v1/orders
     *
     * WAJIB difilter per user. Kalau lupa, satu pelanggan bisa melihat
     * pesanan pelanggan lain — kebocoran data paling serius di toko online.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            // Scope wajib: jangan sampai lupa.
            ->where('user_id', $request->user()->getKey())
            ->with(['items', 'statusHistories'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 10));

        return response()->json([
            'data' => OrderResource::collection($orders->items())->resolve($request),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Detail satu pesanan.
     *
     * GET /api/v1/orders/{orderNumber}
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            // Filter per user DI QUERY, bukan setelahnya.
            ->where('user_id', $request->user()->getKey())
            ->with(['items', 'statusHistories', 'payments'])
            ->first();

        if ($order === null) {
            // 404, bukan 403: keberadaan pesanan milik orang lain pun
            // tidak boleh terkonfirmasi.
            return response()->json([
                'message' => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'data' => (new OrderResource($order))->toArray($request),
        ]);
    }

    /**
     * Batalkan pesanan oleh pelanggan.
     *
     * POST /api/v1/orders/{orderNumber}/cancel
     *
     * Hanya bisa kalau order belum dikirim. Aturannya sudah ada di domain
     * (OrderStatus::allowedTransitions), jadi controller ini cukup memanggil
     * action lalu melaporkan hasilnya.
     */
    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        try {
            $updated = app(TransitionOrderStatus::class)->execute(
                $order,
                OrderStatus::Dibatalkan,
                OrderActor::Customer,
                note: 'Dibatalkan oleh pelanggan.',
            );

            return response()->json([
                'message' => 'Pesanan berhasil dibatalkan.',
                'data' => (new OrderResource($updated->load('items')))->toArray($request),
            ]);
        } catch (InvalidStatusTransitionException $e) {
            // 409: sudah dikirim / sudah dibayar / sudah dibatalkan.
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
