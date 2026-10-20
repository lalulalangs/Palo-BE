<?php

namespace App\Services\Shipping;

use App\Models\Sku;
use Illuminate\Support\Str;

class ShippingRateService
{
    /**
     * Tarif resmi untuk satu kombinasi kurir & layanan, dihitung ulang
     * server-side dari kota tujuan dan berat SKU. Ini adalah Single Source
     * of Truth ongkir saat checkout — nilai yang dikirim client tidak
     * dipercaya (remediasi audit S-02).
     *
     * Alias kurir dinormalisasi (mis. "JNT" ≈ "J&T", "sicepat" ≈ "SiCepat").
     * Kombinasi yang tidak dikenal dikembalikan null (fail-closed): pemanggil
     * wajib menolak checkout, bukan memakai nilai client.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array{courier: string, service: string, description: string, cost: int, etd: string}|null
     */
    public function quoteFor(string $courier, string $service, string $destinationCity, array $items = []): ?array
    {
        $requestedCourier = $this->normalizeCourierKey($courier);
        $requestedService = $this->normalizeCourierKey($service);

        return collect($this->getQuotes($destinationCity, $items))
            ->first(fn (array $quote): bool => $this->normalizeCourierKey($quote['courier']) === $requestedCourier
                && $this->normalizeCourierKey($quote['service']) === $requestedService);
    }

    /**
     * Hilangkan seluruh karakter non-alphanumeric agar perbandingan alias
     * kurir/layanan toleran terhadap kapital dan simbol.
     */
    /**
     * Alias kurir dielenggara eksplisit agar perbandingan toleran terhadap
     * simbol dan penulisan (mis. request "JNT" ≈ tarif "J&T"). Normalisasi
     * murni tanpa tabel ini tidak memadai karena "J&T" menjadi "JT".
     */
    private const COURIER_ALIASES = [
        'JNT' => 'JANDT',
        'JT' => 'JANDT',
        'JANDT' => 'JANDT',
    ];

    private function normalizeCourierKey(string $value): string
    {
        $normalized = (string) preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($value)));

        return self::COURIER_ALIASES[$normalized] ?? $normalized;
    }

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
