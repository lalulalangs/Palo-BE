<?php

namespace App\Services\Shipping;

use App\Exceptions\ShippingProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien RajaOngkir V2 (Komerce) — Shipping Cost API, metode Direct Search.
 *
 * Autentikasi memakai header `key` (bukan `Authorization: Bearer`, walau
 * dokumentasi tips menyebut Bearer — contoh cURL resmi memakai `key`).
 *
 * Kelas ini murni HTTP: tanpa cache, tanpa logika bisnis, tanpa counter kuota.
 * Pemanggil (ShippingRateService) yang bertanggung jawab atas cache — wajib,
 * karena paket Starter hanya memberi 100 HIT/hari dan API ini tanpa sandbox.
 *
 * Referensi: https://rajaongkir.com/docs/shipping-cost
 */
class RajaOngkirClient
{
    public function isConfigured(): bool
    {
        return filled(config('shipping.api_key'));
    }

    /**
     * Cari destinasi domestik berdasarkan nama kota/kecamatan/kelurahan/kode pos.
     *
     * @return array<int, array{id: int, label: string, province_name: ?string, city_name: ?string, district_name: ?string, subdistrict_name: ?string, zip_code: ?string}>
     */
    public function searchDestinations(string $keyword, int $limit = 10): array
    {
        $response = $this->send('pencarian destinasi', fn (): Response => $this->http()->get(
            'destination/domestic-destination',
            ['search' => $keyword, 'limit' => $limit],
        ));

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row) || ! isset($row['id'])) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'label' => (string) ($row['label'] ?? ''),
                'province_name' => $row['province_name'] ?? null,
                'city_name' => $row['city_name'] ?? null,
                'district_name' => $row['district_name'] ?? null,
                'subdistrict_name' => $row['subdistrict_name'] ?? null,
                'zip_code' => $row['zip_code'] ?? null,
            ];
        }, $this->payload($response, 'pencarian destinasi'))));
    }

    /**
     * Hitung tarif domestik untuk satu origin → satu destinasi.
     *
     * `$couriers` adalah kode kurir yang digabung titik dua, mis. `jne:jnt:sicepat`.
     * Berat dikirim apa adanya dalam gram — kurir yang membulatkan.
     *
     * @return array<int, array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string}>
     */
    public function calculateCost(int $originId, int $destinationId, int $weightGrams, string $couriers): array
    {
        $response = $this->send('kalkulasi tarif', fn (): Response => $this->http()
            ->asForm()
            ->post('calculate/domestic-cost', [
                'origin' => $originId,
                'destination' => $destinationId,
                'weight' => $weightGrams,
                'courier' => $couriers,
            ]));

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row) || ! isset($row['code'])) {
                return null;
            }

            return [
                'courier' => (string) ($row['name'] ?? $row['code']),
                'courier_code' => (string) $row['code'],
                'service' => (string) ($row['service'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'cost' => (int) ($row['cost'] ?? 0),
                'etd' => (string) ($row['etd'] ?? ''),
            ];
        }, $this->payload($response, 'kalkulasi tarif'))));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['key' => (string) config('shipping.api_key')])
            ->acceptJson()
            ->timeout((int) config('shipping.timeout_seconds', 6));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('shipping.base_url'), '/');
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(string $context, callable $request): Response
    {
        if (! $this->isConfigured()) {
            throw ShippingProviderException::notConfigured();
        }

        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw ShippingProviderException::unreachable($context, $exception->getMessage());
        }
    }

    /**
     * Ambil `data[]` dari envelope `{meta, data}` RajaOngkir.
     *
     * 404 dianggap "tidak ada hasil" (endpoint pencarian mengembalikan 404
     * beserta `data: null` bila kata kunci tidak cocok).
     *
     * @return array<int, mixed>
     */
    private function payload(Response $response, string $context): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ShippingProviderException::invalidResponse($context, $response->status());
        }

        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful() || ($meta['status'] ?? null) !== 'success') {
            throw ShippingProviderException::rejected(
                context: $context,
                httpStatus: $response->status(),
                detail: (string) ($meta['message'] ?? ''),
                metaCode: (int) ($meta['code'] ?? 0),
            );
        }

        $data = $body['data'] ?? null;

        return is_array($data) ? array_values($data) : [];
    }
}
