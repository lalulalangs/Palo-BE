<?php

namespace App\Observers;

use App\Models\AdminActivityLog;
use App\Models\Sku;
use Illuminate\Support\Facades\Auth;

/**
 * Catat perubahan stok/harga SKU yang dilakukan admin ke `admin_activity_logs`.
 *
 * Perubahan stok dari sistem (penjualan, pembatalan, opname) tidak tercatat di
 * sini karena jalur tersebut memakai `Sku::withoutEvents()`.
 */
class SkuAuditObserver
{
    private const WATCHED = ['stock', 'price_override'];

    public function updated(Sku $sku): void
    {
        $adminId = Auth::guard('admin')->id();

        if (! $adminId) {
            return;
        }

        $changed = array_values(array_filter(
            self::WATCHED,
            fn (string $attribute): bool => $sku->wasChanged($attribute)
        ));

        if ($changed === []) {
            return;
        }

        AdminActivityLog::create([
            'admin_user_id' => $adminId,
            'action' => 'update_sku_inventory',
            'model_type' => Sku::class,
            'model_id' => $sku->getKey(),
            'before_data' => $this->snapshot($sku, $changed, original: true),
            'after_data' => $this->snapshot($sku, $changed, original: false),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(Sku $sku, array $attributes, bool $original): array
    {
        $data = [];

        foreach ($attributes as $attribute) {
            $data[$attribute] = $original
                ? $sku->getOriginal($attribute)
                : $sku->getAttribute($attribute);
        }

        return $data;
    }
}
