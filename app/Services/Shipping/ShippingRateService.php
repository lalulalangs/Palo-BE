<?php

namespace App\Services\Shipping;

use App\Exceptions\ShippingProviderException;
use App\Models\AppSetting;
use App\Models\Sku;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sumber tunggal tarif ongkir.
 *
 * Alur: origin + destination + berat (gram) → RajaOngkir → cache 24 jam →
 * daftar layanan. Cache WAJIB: paket Starter hanya 100 HIT/hari dan API ini
 * tidak punya sandbox. Saat penyedia gagal, tampilan boleh memakai cache basi
 * (maks 7 hari); checkout tidak boleh — itu urusan pemanggil (`quoteFor()`
 * mengembalikan null sehingga checkout menolak pesanan).
 *
 * Konfigurasi operasional (origin, kurir aktif, layanan yang dikecualikan)
 * disimpan di `app_settings`, bukan di kode.
 */
class ShippingRateService
{
    public const SOURCE_API = 'api';

    public const SOURCE_CACHE = 'cache';

    public const SOURCE_STALE = 'stale_cache';

    /**
     * Berat cadangan bila SKU belum punya `weight_grams`. Sengaja dipertahankan
     * agar data yang belum lengkap tidak membuat checkout gagal total.
     */
    private const FALLBACK_WEIGHT_GRAMS = 350;

    /**
     * Layanan kargo tidak relevan untuk e-commerce: tarifnya mulai ratusan
     * ribu sampai jutaan. Daftar ini bisa ditimpa lewat app_settings
     * `shipping_excluded_services` tanpa deploy.
     */
    private const DEFAULT_EXCLUDED_SERVICES = 'JTR,JTR<130,JTR>130,JTR>200';

    private const DESTINATION_CACHE_TTL = 604800;

    /**
     * Nama tampilan kurir. API mengirim `name` yang panjang
     * ("Jalur Nugraha Ekakurir (JNE)"), sedangkan kontrak API kita memakai
     * nama pendek. Hanya berisi label, bukan tarif.
     *
     * @var array<string, string>
     */
    private const COURIER_BRANDS = [
        'jne' => 'JNE',
        'jnt' => 'J&T',
    ];

    public function __construct(private readonly RajaOngkirClient $client) {}

    /**
     * Tarif untuk satu kombinasi kurir & layanan. `null` = tidak tersedia,
     * dan pemanggil wajib menolak checkout (fail-closed).
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string, source: string, origin_id: int, destination_id: int, destination_label: string|null, weight_grams: int, quoted_at: string}|null
     */
    public function quoteFor(
        string $courier,
        string $service,
        string $destinationCity,
        array $items = [],
        ?int $destinationId = null
    ): ?array {
        $requestedCourier = $this->normalizeCourierKey($courier);
        $requestedService = $this->normalizeCourierKey($service);

        $destinationId ??= $this->resolveDestinationId($destinationCity);

        $quote = $this->quote(
            destinationId: $destinationId,
            items: $items,
            destinationLabel: trim($destinationCity) !== '' ? $destinationCity : null,
        );

        $matched = collect($quote['services'])
            ->first(fn (array $row): bool => $this->normalizeCourierKey($row['courier']) === $requestedCourier
                && $this->normalizeCourierKey($row['service']) === $requestedService);

        if ($matched === null) {
            return null;
        }

        // Sertakan metadata supaya pemanggil bisa menyimpan snapshot order.
        return $matched + [
            'source' => $quote['source'],
            'origin_id' => $quote['origin']['id'],
            'destination_id' => $quote['destination']['id'],
            'destination_label' => $quote['destination']['label'],
            'weight_grams' => $quote['weight_grams'],
            'quoted_at' => $quote['quoted_at'],
        ];
    }

    /**
     * Daftar layanan saja — bentuk kontrak lama, dipertahankan agar pemanggil
     * yang sudah ada tidak perlu berubah.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array<int, array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string}>
     */
    public function getQuotes(string $destinationCity, array $items = [], ?int $destinationId = null): array
    {
        $destinationId ??= $this->resolveDestinationId($destinationCity);

        return $this->quote(
            destinationId: $destinationId,
            items: $items,
            destinationLabel: trim($destinationCity) !== '' ? $destinationCity : null,
        )['services'];
    }

    /**
     * Satu kali hitung ongkir lengkap beserta metadata sumber data.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array{services: array<int, array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string}>, weight_grams: int, origin: array{id: int, label: string|null}, destination: array{id: int, label: string|null}, source: string, quoted_at: string}
     */
    public function quote(int $destinationId, array $items, ?string $destinationLabel = null): array
    {
        $originId = $this->originSubdistrictId();
        $weightGrams = $this->totalWeightGrams($items);
        $couriers = $this->enabledCouriers();

        [$rows, $source] = $this->fetchRates($originId, $destinationId, $weightGrams, $couriers);

        return [
            'services' => $this->presentServices($rows),
            'weight_grams' => $weightGrams,
            'origin' => ['id' => $originId, 'label' => AppSetting::getValue('shipping_origin_label')],
            'destination' => ['id' => $destinationId, 'label' => $destinationLabel],
            'source' => $source,
            'quoted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Pencarian destinasi dengan cache 7 hari — data wilayah jarang berubah.
     *
     * @return array<int, array{id: int, label: string, province_name: string|null, city_name: string|null, district_name: string|null, subdistrict_name: string|null, zip_code: string|null}>
     */
    public function searchDestinations(string $keyword, int $limit = 10): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            throw ShippingProviderException::destinationNotFound($keyword);
        }

        $cacheKey = 'shipping:dest:'.sha1(Str::lower($keyword).'|'.$limit);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->client->searchDestinations($keyword, $limit);
        $this->recordApiHit();

        Cache::put($cacheKey, $rows, self::DESTINATION_CACHE_TTL);

        return $rows;
    }

    /**
     * Label destinasi yang ramah dibaca manusia.
     *
     * API mengirim nama wilayah dalam huruf kapital penuh dan provinsi
     * panjang ("NUSA TENGGARA BARAT (NTB)"), yang jelek untuk ditampilkan.
     * Hasilnya: "Bayan, Lombok Utara, NTB, 83354".
     *
     * @param  array<string, mixed>  $destination
     */
    public static function readableDestinationLabel(array $destination): string
    {
        $parts = array_filter([
            self::titleCaseName((string) ($destination['subdistrict_name'] ?? '')),
            self::titleCaseName((string) ($destination['city_name'] ?? '')),
            self::shortProvinceName((string) ($destination['province_name'] ?? '')),
            trim((string) ($destination['zip_code'] ?? '')),
        ]);

        $label = implode(', ', $parts);

        // Jaga-jaga bila bentuk respons API berubah.
        return $label !== '' ? $label : trim((string) ($destination['label'] ?? ''));
    }

    /**
     * Kapitalisasi nama wilayah tanpa merusak akronim pendek (DKI, DIY, NTB).
     */
    private static function titleCaseName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $words = preg_split('/\s+/', $name) ?: [];

        return implode(' ', array_map(function (string $word): string {
            if (mb_strlen($word) <= 4 && $word === mb_strtoupper($word)) {
                return $word;
            }

            return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
        }, $words));
    }

    /**
     * Ambil singkatan provinsi dari tanda kurung bila ada.
     * "NUSA TENGGARA BARAT (NTB)" → "NTB", "DKI JAKARTA" → "DKI Jakarta".
     */
    private static function shortProvinceName(string $province): string
    {
        $province = trim($province);

        if (preg_match('/\(([^)]+)\)\s*$/', $province, $matches) === 1 && mb_strlen($matches[1]) <= 6) {
            return mb_strtoupper($matches[1]);
        }

        return self::titleCaseName($province);
    }

    /**
     * Jembatan kompatibilitas: frontend lama masih mengirim nama kota bebas.
     * Hasil pencarian di-cache, jadi biayanya hanya sekali per kata kunci.
     */
    public function resolveDestinationId(string $keyword): int
    {
        $rows = $this->searchDestinations($keyword, 1);

        if ($rows === []) {
            throw ShippingProviderException::destinationNotFound(trim($keyword));
        }

        return $rows[0]['id'];
    }

    /**
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     */
    public function totalWeightGrams(array $items): int
    {
        $skuIds = collect($items)
            ->pluck('sku_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $skus = $skuIds->isEmpty()
            ? collect()
            : Sku::query()->whereIn('id', $skuIds)->get()->keyBy('id');

        $total = 0;

        foreach ($items as $item) {
            $sku = $skus->get((int) ($item['sku_id'] ?? 0));
            $quantity = (int) ($item['quantity'] ?? 1);
            $actualWeight = (int) ($sku?->weight_grams ?? self::FALLBACK_WEIGHT_GRAMS);

            // Kurir menagih berat terbesar antara aktual dan volumetrik.
            $total += max($actualWeight, $this->volumetricWeightGrams($sku)) * $quantity;
        }

        $maxWeight = (int) config('shipping.max_weight_grams', 50000);

        if ($total > $maxWeight) {
            throw ShippingProviderException::weightTooHeavy($total, $maxWeight);
        }

        // Gram dikirim apa adanya: pembulatan ke kilogram dilakukan kurir.
        return max(1, $total);
    }

    /**
     * Berat volumetrik dalam gram, dihitung app-side karena endpoint
     * `calculate/domestic-cost` tidak menerima dimensi.
     *
     * Rumus kurir domestik: P x L x T (cm) / 6000 = kilogram.
     * Dikembalikan 0 bila dimensi belum diisi, sehingga diabaikan.
     */
    private function volumetricWeightGrams(?Sku $sku): int
    {
        $length = (int) ($sku?->length_cm ?? 0);
        $width = (int) ($sku?->width_cm ?? 0);
        $height = (int) ($sku?->height_cm ?? 0);

        if ($length <= 0 || $width <= 0 || $height <= 0) {
            return 0;
        }

        return (int) ceil($length * $width * $height / 6000 * 1000);
    }

    private function originSubdistrictId(): int
    {
        $originId = (int) AppSetting::getValue('shipping_origin_subdistrict_id', '0');

        if ($originId <= 0) {
            throw ShippingProviderException::notConfigured();
        }

        return $originId;
    }

    private function enabledCouriers(): string
    {
        $couriers = trim((string) AppSetting::getValue('shipping_enabled_couriers', 'jne:jnt'));

        if ($couriers === '') {
            throw ShippingProviderException::notConfigured();
        }

        return $couriers;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function fetchRates(int $originId, int $destinationId, int $weightGrams, string $couriers): array
    {
        $cacheKey = 'shipping:rates:'.sha1(implode('|', [$originId, $destinationId, $weightGrams, $couriers]));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [$cached, self::SOURCE_CACHE];
        }

        try {
            $rows = $this->client->calculateCost($originId, $destinationId, $weightGrams, $couriers);
        } catch (ShippingProviderException $exception) {
            $stale = Cache::get($cacheKey.':stale');

            if ($exception->isTransient() && is_array($stale)) {
                Log::warning('RajaOngkir gagal; memakai cache basi untuk tampilan ongkir.', [
                    'error_code' => $exception->errorCode,
                    'context' => $exception->context,
                ]);

                return [$stale, self::SOURCE_STALE];
            }

            throw $exception;
        }

        $this->recordApiHit();

        Cache::put($cacheKey, $rows, (int) config('shipping.cache_ttl', 86400));
        Cache::put($cacheKey.':stale', $rows, (int) config('shipping.stale_ttl', 604800));

        return [$rows, self::SOURCE_API];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string}>
     */
    private function presentServices(array $rows): array
    {
        $excluded = $this->excludedServices();
        $services = [];

        foreach ($rows as $row) {
            $service = strtoupper(trim((string) ($row['service'] ?? '')));
            $cost = (int) ($row['cost'] ?? 0);

            if ($service === '' || $cost <= 0 || in_array($service, $excluded, true)) {
                continue;
            }

            $services[] = [
                'courier' => $this->courierBrand($row),
                'courier_code' => (string) ($row['courier_code'] ?? ''),
                'service' => $service,
                'description' => (string) ($row['description'] ?? ''),
                'cost' => $cost,
                'etd' => (string) ($row['etd'] ?? ''),
            ];
        }

        usort($services, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

        return $services;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function courierBrand(array $row): string
    {
        $code = Str::lower((string) ($row['courier_code'] ?? ''));

        if (isset(self::COURIER_BRANDS[$code])) {
            return self::COURIER_BRANDS[$code];
        }

        $name = trim((string) ($row['courier'] ?? ''));

        // "Jalur Nugraha Ekakurir (JNE)" → "JNE"
        if (preg_match('/\(([^)]+)\)\s*$/', $name, $matches) === 1) {
            return Str::upper(trim($matches[1]));
        }

        return $name !== '' ? $name : Str::upper($code);
    }

    /**
     * @return array<int, string>
     */
    private function excludedServices(): array
    {
        $raw = (string) AppSetting::getValue('shipping_excluded_services', self::DEFAULT_EXCLUDED_SERVICES);

        return array_values(array_filter(array_map(
            fn (string $service): string => strtoupper(trim($service)),
            explode(',', $raw),
        )));
    }

    /**
     * Hitung pemakaian kuota harian; API ini tidak punya sandbox sehingga
     * satu request nyata = satu HIT.
     */
    private function recordApiHit(): void
    {
        $cacheKey = 'shipping:hits:'.now()->toDateString();

        Cache::add($cacheKey, 0, 172800);
        $hits = (int) Cache::increment($cacheKey);
        $quota = (int) config('shipping.daily_hit_quota', 100);

        if ($hits === (int) round($quota * 0.8)) {
            Log::warning('Kuota RajaOngkir sudah mencapai 80%.', ['hits' => $hits, 'quota' => $quota]);
        }
    }

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
}
