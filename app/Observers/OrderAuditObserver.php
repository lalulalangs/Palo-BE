<?php

namespace App\Observers;

use App\Models\AdminActivityLog;
use App\Models\Order;
use BackedEnum;
use Illuminate\Support\Facades\Auth;

/**
 * Catat perubahan status/resi pesanan yang dilakukan admin ke
 * `admin_activity_logs` (non-repudiation untuk aksi operasional).
 */
class OrderAuditObserver
{
    private const WATCHED = ['status', 'tracking_number', 'cancelled_reason'];

    public function updated(Order $order): void
    {
        $adminId = Auth::guard('admin')->id();

        if (! $adminId) {
            return;
        }

        $changed = array_values(array_filter(
            self::WATCHED,
            fn (string $attribute): bool => $order->wasChanged($attribute)
        ));

        if ($changed === []) {
            return;
        }

        AdminActivityLog::create([
            'admin_user_id' => $adminId,
            'action' => $order->wasChanged('status') ? 'update_order_status' : 'update_order_shipping',
            'model_type' => Order::class,
            'model_id' => $order->getKey(),
            'before_data' => $this->snapshot($order, $changed, original: true),
            'after_data' => $this->snapshot($order, $changed, original: false),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(Order $order, array $attributes, bool $original): array
    {
        $data = [];

        foreach ($attributes as $attribute) {
            $value = $original
                ? $order->getOriginal($attribute)
                : $order->getAttribute($attribute);

            $data[$attribute] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $data;
    }
}
