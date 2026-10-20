<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireUnconfirmedCodOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected User $buyer;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create(['email' => 'buyer-timeout@example.com']);

        $category = Category::create(['name' => 'Carrier', 'slug' => 'carrier']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Carrier Rinjani 60L',
            'slug' => 'carrier-rinjani-60l',
            'base_price' => 850000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy Blue',
            'attributes' => ['color' => 'Navy'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'CRR-RNJ-60L-NVY',
            'stock' => 10,
        ]);
    }

    protected function createCodOrder(string $status, int $hoursAgo, ?int $voucherId = null): Order
    {
        $createdAt = now()->subHours($hoursAgo);

        $order = Order::create([
            'order_number' => 'COD-'.date('Ymd', $createdAt->timestamp).'-'.fake()->unique()->numerify('#####'),
            'user_id' => $this->buyer->id,
            'voucher_id' => $voucherId,
            'status' => $status,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-REF-'.fake()->unique()->numerify('#####'),
            'subtotal' => 850000,
            'discount_amount' => $voucherId ? 50000 : 0,
            'shipping_cost' => 30000,
            'total' => 850000 + 30000 - ($voucherId ? 50000 : 0),
            'shipping_recipient_name' => 'Hendra Setiawan',
            'shipping_phone' => '081298765432',
            'shipping_full_address' => 'Sembalun Bumbung, Lombok Timur',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        Order::withoutTimestamps(function () use ($order, $createdAt) {
            $order->timestamps = false;
            $order->created_at = $createdAt;
            $order->updated_at = $createdAt;
            $order->save();
        });

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Carrier Rinjani 60L',
            'variant_name_snapshot' => 'Navy Blue',
            'sku_code_snapshot' => 'CRR-RNJ-60L-NVY',
            'price_snapshot' => 850000,
            'quantity' => 1,
            'subtotal' => 850000,
        ]);

        return $order->fresh();
    }

    public function test_expires_cod_orders_older_than_default_24_hours(): void
    {
        // 1. Pesanan COD dibuat 25 jam yang lalu (melebihi batas 24 jam)
        $expiredCodOrder = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 25);

        // 2. Pesanan COD dibuat 2 jam yang lalu (masih dalam batas 24 jam)
        $freshCodOrder = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);

        // 3. Pesanan Online dibuat 30 jam yang lalu (harus diabaikan oleh command ini)
        $onlineOrder = Order::create([
            'order_number' => 'ONL-OLD-001',
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 850000,
            'discount_amount' => 0,
            'shipping_cost' => 30000,
            'total' => 880000,
            'shipping_recipient_name' => 'Online Buyer',
            'shipping_phone' => '081211112222',
            'shipping_full_address' => 'Mataram',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);
        Order::withoutTimestamps(function () use ($onlineOrder) {
            $onlineOrder->timestamps = false;
            $onlineOrder->created_at = now()->subHours(30);
            $onlineOrder->updated_at = now()->subHours(30);
            $onlineOrder->save();
        });

        // 4. Pesanan COD yang sudah dikonfirmasi (processing) dibuat 30 jam lalu (harus diabaikan)
        $processingCodOrder = $this->createCodOrder(Order::STATUS_PROCESSING, 30);

        $this->artisan('orders:expire-unconfirmed-cod')
            ->expectsOutputToContain('Ditemukan 1 pesanan COD yang memenuhi kriteria kedaluwarsa.')
            ->expectsOutputToContain("Pesanan COD #{$expiredCodOrder->order_number} berhasil dikedaluwarsakan.")
            ->assertSuccessful();

        $this->assertEquals(OrderStatus::Expired, $expiredCodOrder->fresh()->status);
        $this->assertStringContainsStringIgnoringCase('batas waktu konfirmasi CS', $expiredCodOrder->fresh()->cancelled_reason);

        // Pesanan lain tidak tersentuh
        $this->assertEquals(OrderStatus::AwaitingConfirmation, $freshCodOrder->fresh()->status);
        $this->assertEquals(OrderStatus::Pending, $onlineOrder->fresh()->status);
        $this->assertEquals(OrderStatus::Processing, $processingCodOrder->fresh()->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $expiredCodOrder->id,
            'from_status' => Order::STATUS_AWAITING_CONFIRMATION,
            'to_status' => Order::STATUS_EXPIRED,
        ]);
    }

    public function test_respects_custom_hours_option(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 5);

        // Jika batas waktu di-set 6 jam, order 5 jam lalu belum kedaluwarsa
        $this->artisan('orders:expire-unconfirmed-cod', ['--hours' => 6])
            ->expectsOutputToContain('Tidak ditemukan pesanan COD yang kedaluwarsa.')
            ->assertSuccessful();

        $this->assertEquals(OrderStatus::AwaitingConfirmation, $order->fresh()->status);

        // Jika batas waktu di-set 4 jam, order 5 jam lalu harus kedaluwarsa
        $this->artisan('orders:expire-unconfirmed-cod', ['--hours' => 4])
            ->expectsOutputToContain("Pesanan COD #{$order->order_number} berhasil dikedaluwarsakan.")
            ->assertSuccessful();

        $this->assertEquals(OrderStatus::Expired, $order->fresh()->status);
    }

    public function test_respects_app_setting_timeout_value(): void
    {
        AppSetting::setValue('cod_confirmation_timeout_hours', '12');

        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 13);

        $this->artisan('orders:expire-unconfirmed-cod')
            ->expectsOutputToContain('Memeriksa pesanan COD yang belum dikonfirmasi lebih dari 12 jam')
            ->expectsOutputToContain("Pesanan COD #{$order->order_number} berhasil dikedaluwarsakan.")
            ->assertSuccessful();

        $this->assertEquals(OrderStatus::Expired, $order->fresh()->status);
    }

    public function test_dry_run_option_does_not_mutate_database(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 30);

        $this->artisan('orders:expire-unconfirmed-cod', ['--dry-run' => true])
            ->expectsOutputToContain("[DRY-RUN] Pesanan COD #{$order->order_number}")
            ->expectsOutputToContain('[DRY-RUN] Selesai simulasi. 1 pesanan teridentifikasi.')
            ->assertSuccessful();

        // Status di database tetap awaiting_confirmation
        $this->assertEquals(OrderStatus::AwaitingConfirmation, $order->fresh()->status);
        $this->assertDatabaseMissing('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_EXPIRED,
        ]);
    }

    public function test_recovers_voucher_quota_when_expired(): void
    {
        $voucher = Voucher::create([
            'code' => 'CODTIMEOUT',
            'type' => Voucher::TYPE_FIXED,
            'value' => 50000,
            'min_purchase' => 500000,
            'quota' => 20,
            'used_count' => 10,
            'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(5),
            'is_active' => true,
        ]);

        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 26, $voucher->id);
        $this->assertEquals(10, $voucher->fresh()->used_count);

        $this->artisan('orders:expire-unconfirmed-cod')->assertSuccessful();

        $this->assertEquals(OrderStatus::Expired, $order->fresh()->status);
        $this->assertEquals(9, $voucher->fresh()->used_count);
    }

    public function test_scheduled_task_is_registered_in_schedule(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command ?? '', 'orders:expire-unconfirmed-cod');
        });

        $this->assertNotEmpty($events, 'Command orders:expire-unconfirmed-cod belum terdaftar pada scheduler.');
        $this->assertEquals('0 * * * *', $events->first()->expression);
    }
}
