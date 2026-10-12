<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyVoucherRequest;
use App\Http\Resources\Api\V1\VoucherResource;
use App\Models\Sku;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherService;
use Illuminate\Http\JsonResponse;

class VoucherController extends Controller
{
    public function __construct(
        protected VoucherService $voucherService
    ) {}

    /**
     * Tampilkan daftar voucher promosi yang aktif dan tersedia untuk pembeli.
     */
    public function index(): JsonResponse
    {
        $vouchers = Voucher::query()
            ->available()
            ->orderBy('min_purchase', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar voucher promosi berhasil diambil.',
            'data' => VoucherResource::collection($vouchers),
        ]);
    }

    /**
     * Terapkan dan validasi kode voucher pada subtotal atau daftar item keranjang.
     */
    public function apply(ApplyVoucherRequest $request): JsonResponse
    {
        $subtotal = 0.0;

        if ($request->filled('subtotal')) {
            $subtotal = (float) $request->input('subtotal');
        } elseif ($request->has('items') && is_array($request->input('items'))) {
            foreach ($request->input('items') as $line) {
                $sku = Sku::find($line['sku_id']);
                if ($sku && $sku->is_active) {
                    $subtotal += $sku->effective_price * (int) $line['quantity'];
                }
            }
        }

        $result = $this->voucherService->validate(
            code: $request->validated('code'),
            subtotal: $subtotal,
            user: $request->user('sanctum'),
            fieldKey: 'code'
        );

        return response()->json([
            'success' => true,
            'message' => 'Voucher berhasil diterapkan.',
            'data' => [
                'voucher_id' => $result->voucher->id,
                'code' => $result->voucher->code,
                'type' => $result->voucher->type,
                'value' => (float) $result->voucher->value,
                'formatted_value' => $result->voucher->formattedValue(),
                'min_purchase' => (float) $result->voucher->min_purchase,
                'formatted_min_purchase' => $result->voucher->formattedMinPurchase(),
                'discount_amount' => $result->discountAmount,
                'formatted_discount_amount' => 'Rp '.number_format($result->discountAmount, 0, ',', '.'),
                'subtotal' => $result->subtotal,
                'formatted_subtotal' => 'Rp '.number_format($result->subtotal, 0, ',', '.'),
                'final_subtotal' => $result->finalSubtotal,
                'formatted_final_subtotal' => 'Rp '.number_format($result->finalSubtotal, 0, ',', '.'),
            ],
        ]);
    }
}
