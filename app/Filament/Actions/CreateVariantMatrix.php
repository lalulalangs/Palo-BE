<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat banyak varian + SKU sekaligus dari matriks warna × ukuran.
 *
 * Satu grup warna menghasilkan satu varian per ukuran terpilih. Stok,
 * harga override, dan status aktif berlaku sama untuk semua ukuran dalam
 * grup tersebut. Kode SKU dibuat otomatis dan dijamin unik; admin bisa
 * mengubahnya lagi lewat aksi Edit per baris.
 */
class CreateVariantMatrix
{
    public const SIZES = ['S', 'M', 'L', 'XL', 'XXL', 'All Size'];

    /**
     * @param  array<int, array{color?: string, sizes?: array<int, string>, stock?: int, price_override?: float|null, is_active?: bool}>  $groups
     * @return array<int, ProductVariant>
     */
    public function execute(Product $product, array $groups): array
    {
        $groups = $this->validate($groups);

        return DB::transaction(function () use ($product, $groups): array {
            $created = [];

            foreach ($groups as $group) {
                foreach ($group['sizes'] as $size) {
                    $color = $group['color'];

                    $variant = ProductVariant::create([
                        'product_id' => $product->getKey(),
                        'name' => "{$color} / Size {$size}",
                        'attributes' => ['color' => $color, 'size' => $size],
                    ]);

                    $variant->skus()->create([
                        'sku_code' => $this->uniqueSkuCode($product, $color, $size),
                        'price_override' => $group['price_override'],
                        'stock' => $group['stock'],
                        'is_active' => $group['is_active'],
                    ]);

                    $created[] = $variant;
                }
            }

            return $created;
        });
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return array<int, array{color: string, sizes: array<int, string>, stock: int, price_override: float|null, is_active: bool}>
     */
    private function validate(array $groups): array
    {
        if ($groups === []) {
            throw ValidationException::withMessages([
                'colors' => 'Tambahkan minimal satu warna.',
            ]);
        }

        $validated = [];

        foreach (array_values($groups) as $index => $group) {
            $group = is_array($group) ? $group : [];
            $color = trim((string) ($group['color'] ?? ''));
            $sizes = array_values(array_intersect(
                array_map('strval', (array) ($group['sizes'] ?? [])),
                self::SIZES
            ));

            if ($color === '') {
                throw ValidationException::withMessages([
                    "colors.{$index}.color" => 'Nama warna wajib diisi.',
                ]);
            }

            if ($sizes === []) {
                throw ValidationException::withMessages([
                    "colors.{$index}.sizes" => "Pilih minimal satu ukuran untuk warna {$color}.",
                ]);
            }

            $stock = (int) ($group['stock'] ?? 0);
            $priceOverride = $group['price_override'] ?? null;
            $priceOverride = $priceOverride === null || $priceOverride === '' ? null : (float) $priceOverride;

            if ($stock < 0 || ($priceOverride !== null && $priceOverride < 0)) {
                throw ValidationException::withMessages([
                    "colors.{$index}.stock" => 'Stok dan harga tidak boleh negatif.',
                ]);
            }

            $validated[] = [
                'color' => $color,
                'sizes' => $sizes,
                'stock' => $stock,
                'price_override' => $priceOverride,
                'is_active' => (bool) ($group['is_active'] ?? true),
            ];
        }

        return $validated;
    }

    private function uniqueSkuCode(Product $product, string $color, string $size): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($product->slug)));
        $prefix = substr($prefix, 0, 6);
        $prefix = $prefix !== '' ? $prefix : 'PRD';
        $colorPart = preg_replace('/[^A-Z0-9]/', '', strtoupper($color));
        $colorPart = substr(str_pad($colorPart !== '' ? $colorPart : 'WRN', 3, 'X'), 0, 3);
        $sizePart = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $size));

        $base = "{$prefix}-{$colorPart}-{$sizePart}";
        $code = $base;
        $suffix = 2;

        while (Sku::where('sku_code', $code)->exists()) {
            $code = "{$base}-{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
