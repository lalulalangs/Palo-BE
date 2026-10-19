<?php

namespace App\Services\Shipping;

use App\Models\Sku;
use Illuminate\Support\Str;

class ShippingRateService
{
    /**
     * Calculate shipping rates for given destination city and items.
     *
     * Berat total diambil dari `skus.weight_grams` (default 350 g) agar
     * perhitungan tidak lagi memukul rata per kuantitas.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array<int, array{courier: string, service: string, description: string, cost: int, etd: string}>
     */
    public function getQuotes(string $destinationCity, array $items = []): array
    {
        $cityUpper = strtoupper(trim($destinationCity));

        // If courier service outage simulation is triggered
        if (config('services.courier.simulate_down', false) || $cityUpper === 'SIMULATE_DOWN') {
            abort(503, 'Layanan kurir pengiriman sedang tidak dapat dihubungi. Silakan coba beberapa saat lagi.');
        }

        $skuIds = collect($items)
            ->pluck('sku_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $skus = $skuIds->isEmpty()
            ? collect()
            : Sku::query()->whereIn('id', $skuIds)->get()->keyBy('id');

        $totalWeightGrams = 0;

        foreach ($items as $item) {
            $skuId = (int) ($item['sku_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $weightGrams = (int) ($skus->get($skuId)?->weight_grams ?? 350);

            $totalWeightGrams += $weightGrams * $quantity;
        }

        $weightKg = max(1, (int) ceil($totalWeightGrams / 1000));

        // Distance base multiplier: Mataram / NTB is local, Java / Bali is medium, other regions higher
        $multiplier = 1.0;
        if (Str::contains($cityUpper, ['MATARAM', 'LOMBOK', 'SENARU'])) {
            $multiplier = 0.5;
        } elseif (Str::contains($cityUpper, ['DENPASAR', 'BALI', 'SURABAYA'])) {
            $multiplier = 0.8;
        } elseif (Str::contains($cityUpper, ['JAKARTA', 'BANDUNG', 'SEMARANG', 'YOGYAKARTA'])) {
            $multiplier = 1.0;
        } else {
            $multiplier = 1.3;
        }

        $baseJneReg = (int) round(28000 * $multiplier * $weightKg);
        $baseJneYes = (int) round(45000 * $multiplier * $weightKg);
        $baseJntEz = (int) round(27000 * $multiplier * $weightKg);
        $baseSicepat = (int) round(26000 * $multiplier * $weightKg);

        return [
            [
                'courier' => 'JNE',
                'service' => 'REG',
                'description' => 'Layanan Reguler',
                'cost' => $baseJneReg,
                'etd' => '2-3 hari',
            ],
            [
                'courier' => 'JNE',
                'service' => 'YES',
                'description' => 'Yakin Esok Sampai',
                'cost' => $baseJneYes,
                'etd' => '1 hari',
            ],
            [
                'courier' => 'J&T',
                'service' => 'EZ',
                'description' => 'Layanan Reguler',
                'cost' => $baseJntEz,
                'etd' => '2-3 hari',
            ],
            [
                'courier' => 'SiCepat',
                'service' => 'REG',
                'description' => 'SiCepat Reguler',
                'cost' => $baseSicepat,
                'etd' => '2-3 hari',
            ],
        ];
    }
}
