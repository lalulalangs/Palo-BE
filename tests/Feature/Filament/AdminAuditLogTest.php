<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Models\AdminActivityLog;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_stock_update_is_recorded(): void
    {
        $admin = AdminUser::factory()->create();
        $sku = $this->createSku(stock: 10);

        $this->actingAs($admin, 'admin');

        $sku->update(['stock' => 7]);

        $log = AdminActivityLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('update_sku_inventory', $log->action);
        $this->assertSame(Sku::class, $log->model_type);
        $this->assertSame($sku->id, $log->model_id);
        $this->assertSame(10, (int) $log->before_data['stock']);
        $this->assertSame(7, (int) $log->after_data['stock']);
        $this->assertNotNull($log->ip_address);
    }

    public function test_admin_price_update_is_recorded(): void
    {
        $admin = AdminUser::factory()->create();
        $sku = $this->createSku(stock: 10);

        $this->actingAs($admin, 'admin');

        $sku->update(['price_override' => 125000]);

        $log = AdminActivityLog::query()->where('action', 'update_sku_inventory')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(125000.0, (float) $log->after_data['price_override']);
    }

    public function test_system_context_does_not_write_admin_audit_log(): void
    {
        $sku = $this->createSku(stock: 10);

        // Tanpa admin login (mis. job/console), perubahan tidak diatribusikan ke admin.
        $sku->update(['stock' => 5]);

        $this->assertSame(0, AdminActivityLog::count());
    }

    public function test_admin_order_status_transition_is_recorded(): void
    {
        $admin = AdminUser::factory()->create();
        $buyer = User::create([
            'name' => 'Buyer',
            'email' => 'buyer-audit@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $order = Order::create([
            'order_number' => 'UJN-20261019-AUDIT1',
            'user_id' => $buyer->id,
            'status' => OrderStatus::Paid,
            'subtotal' => 100000,
            'total' => 100000,
            'stock_locked_until' => now()->addMinutes(15),
        ]);

        $this->actingAs($admin, 'admin');

        app(OrderTransitionService::class)->markAsProcessing($order, $admin->id);

        $log = AdminActivityLog::query()
            ->where('action', 'update_order_status')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame(Order::class, $log->model_type);
        $this->assertSame($order->id, $log->model_id);
        $this->assertSame(OrderStatus::Paid->value, $log->before_data['status']);
        $this->assertSame(OrderStatus::Processing->value, $log->after_data['status']);
    }

    private function createSku(int $stock): Sku
    {
        $category = Category::create(['name' => 'Kat', 'slug' => 'kat-'.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk',
            'slug' => 'produk-'.uniqid(),
            'base_price' => 100000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'attributes' => [],
        ]);

        return Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'AUDIT-'.uniqid(),
            'price_override' => 100000,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }
}
