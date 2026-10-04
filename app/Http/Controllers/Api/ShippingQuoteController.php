<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cart\Services\CartResolver;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Checkout\Services\CourierUnavailableException;
use App\Domain\Checkout\Services\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint kalkulasi ongkir.
 *
 * PRD §3.8: "Integrasi API kurir logistik untuk kalkulasi ongkos kirim dinamis
 * berdasarkan berat/dimensi produk terverifikasi, kota asal Senaru ... dan
 * kota tujuan."
 *
 * PRD §3.8 AC: "Given API kurir eksternal timeout/down, When buyer mencoba
 * lanjut ke pembayaran, Then sistem memblokir proses checkout dengan pesan error
 * yang jelas, bukan estimasi ongkir yang salah."
 *
 * CourierUnavailableException di-mapping ke HTTP 503 di bootstrap/app.php.
 * Frontend WAJIB memblokir tombol lanjut pembayaran saat menerima 503.
 */
class ShippingQuoteController extends Controller
{
    public function quote(
        Request $request,
        ShippingQuoteService $quoteService,
        CartResolver $cartResolver,
    ): JsonResponse {
        $validated = $request->validate([
            'destination_city_code' => ['required', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'destination_city_code.required' => 'Kota tujuan wajib dipilih.',
        ]);

        // Ambil berat dari SKU di SERVER, bukan dari client. Client boleh
        // mengarang angka berat untuk mendapat ongkir murah.
        $skus = Sku::whereIn('id', array_column($validated['items'], 'sku_id'))
            ->where('is_active', true)
            ->get(['id', 'weight_grams']);

        $weightById = $skus->keyBy('id');

        $parcelsInput = [];
        foreach ($validated['items'] as $item) {
            $sku = $weightById[$item['sku_id']] ?? null;

            $parcelsInput[] = [
                'weight_grams' => (float) ($sku?->weight_grams ?? 0),
                'quantity' => (int) $item['quantity'],
            ];
        }

        $parcels = ShippingQuoteService::buildParcels($parcelsInput);

        $services = $quoteService->quote($validated['destination_city_code'], $parcels);

        // Simpan indeks biaya terverifikasi supaya OrderPricingService bisa
        // mengambil nilainya tanpa mempercayai angka dari client.
        $quoteService->cacheVerifiedCosts(
            $validated['destination_city_code'],
            $parcels,
            $services,
        );

        return response()->json([
            'data' => [
                'destination_city_code' => $validated['destination_city_code'],
                'total_weight_grams' => (int) $parcels[0]['weight_grams'],
                'services' => $services,
            ],
        ]);
    }
}
