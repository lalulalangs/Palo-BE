<?php

namespace App\Filament\Components;

use App\Models\Sku;
use Filament\Forms\Components\Select;

/**
 * Select SKU yang mencari ke database (maks. 50 baris) alih-alih memuat
 * seluruh katalog ke memori setiap kali form dibuka.
 */
class SkuSelect extends Select
{
    protected bool $showsStock = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $this->searchResults($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->optionLabel($value));
    }

    public function withStock(bool $condition = true): static
    {
        $this->showsStock = $condition;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    protected function searchResults(string $search): array
    {
        return Sku::query()
            ->with(['product', 'variant'])
            ->when(filled($search), function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('sku_code', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('sku_code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Sku $sku): array => [$sku->id => $this->formatLabel($sku)])
            ->all();
    }

    protected function optionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $sku = Sku::with(['product', 'variant'])->find((int) $value);

        return $sku ? $this->formatLabel($sku) : null;
    }

    protected function formatLabel(Sku $sku): string
    {
        $productName = $sku->product?->name ?? 'Produk';
        $variantName = $sku->variant?->name ? " ({$sku->variant->name})" : '';
        $stock = $this->showsStock ? " [Stok Saat Ini: {$sku->stock}]" : '';

        return "{$sku->sku_code} — {$productName}{$variantName}{$stock}";
    }
}
