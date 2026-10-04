<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cart\Services\CartResolver;
use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;
use App\Domain\Voucher\Actions\ApplyVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint validasi voucher.
 *
 * PENTING: endpoint ini HANYA untuk pratinjau. Kuota voucher BENAR-BENAR
 * baru dikurangi saat checkout final, lewat ApplyVoucher::commitRedemption()
 * dengan conditional update atomik.
 *
 * PRD §6: "Validasi kuota voucher dilakukan atomik di level database saat
 * checkout final, bukan hanya saat 'apply' di cart."
 *
 * Jadi user boleh berkali-kali mencoba kode voucher tanpa menguras kuota. Itu
 * memang perilaku yang benar.
 */
class VoucherController extends Controller
{
    public function apply(
        Request $request,
        ApplyVoucher $applyVoucher,
        CartResolver $cartResolver,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ], [
            'code.required' => 'Kode voucher wajib diisi.',
        ]);

        $cart = $cartResolver->for($request);

        // Subtotal dihitung dari database, bukan dari angka kiriman client.
        $subtotal = (int) $cart->items->sum(function ($item) {
            return ((int) ($item->sku?->price ?? 0)) * $item->quantity;
        });

        try {
            $result = $applyVoucher->execute($validated['code'], $subtotal);
        } catch (VoucherNotApplicableException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['code' => $e->reasons ?: [$e->getMessage()]],
            ], 422);
        }

        $voucher = $result['voucher'];

        return response()->json([
            'message' => 'Voucher dapat digunakan.',
            'data' => [
                'code' => $voucher->code,
                'type' => $voucher->type->value,
                'type_label' => $voucher->type->label(),
                'description' => $voucher->description,
                'discount' => $result['discount'],

                // PENTING: tekankan ke frontend bahwa ini belum final.
                'note' => 'Potongan ini sudah dihitung ulang dan divalidasi ulang '
                    .'saat pesanan dibuat. Kuota voucher bisa habis sewaktu-waktu.',
            ],
        ]);
    }
}
