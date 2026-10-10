<?php

namespace Tests\Feature\Orders;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class OrderTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected OrderTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create(['email' => 'admin@palorinjani.com']);
        $this->buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $this->service = app(OrderTransitionService::class);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Rinjani Waterproof',
            'slug' => 'jaket-rinjani-waterproof',
            'base_price' => 500000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Forest Green - L',
            'attributes' => ['color' => 'Forest Green', 'size' => 'L'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PALO-JKT-GRN-L',
            'stock' => 15,
        ]);
    }

    protected function createSampleOrder(string $status = Order::STATUS_PENDING, int $qty = 2): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-'.date('Ymd').'-'.fake()->unique()->numerify('#####'),
            'user_id' => $this->buyer->id,
            'status' => $status,
            'subtotal' => 500000 * $qty,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => (500000 * $qty) + 20000,
            'shipping_recipient_name' => 'Budi Santoso',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Pariwisata No. 12, Senaru, Lombok Utara, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Jaket Rinjani Waterproof',
            'variant_name_snapshot' => 'Forest Green - L',
            'sku_code_snapshot' => 'PALO-JKT-GRN-L',
            'price_snapshot' => 500000,
            'quantity' => $qty,
            'subtotal' => 500000 * $qty,
        ]);

        return $order->fresh(['items']);
    }

    public function test_mark_as_paid_deducts_stock_and_creates_sale_movement(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 3);

        $result = $this->service->markAsPaid($order, $this->admin->id, 'Pembayaran Midtrans VA sukses');

        $this->assertEquals(Order::STATUS_PAID, $result->status);
        $this->assertEquals(12, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_SALE,
            'quantity_change' => -3,
            'stock_before' => 15,
            'stock_after' => 12,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'changed_by_admin_id' => $this->admin->id,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran Midtrans VA sukses',
        ]);
    }

    public function test_status_history_actor_relation_resolves_to_admin_user(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 1);

        $this->service->markAsPaid($order, $this->admin->id, 'Pembayaran terverifikasi');

        $history = OrderStatusHistory::where('order_id', $order->id)->latest('id')->firstOrFail();

        $this->assertInstanceOf(AdminUser::class, $history->changedBy);
        $this->assertInstanceOf(AdminUser::class, $history->changedByAdmin);
        $this->assertTrue($history->changedBy->is($this->admin));
        $this->assertTrue($history->changedByAdmin->is($this->admin));
    }

    public function test_mark_as_paid_updates_payment_status_if_payment_exists(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 1);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-9988',
            'payment_method' => 'bank_transfer_bca',
            'amount' => 520000,
            'status' => Payment::STATUS_PENDING,
        ]);

        $result = $this->service->markAsPaid($order, $this->admin->id);

        $this->assertEquals(Order::STATUS_PAID, $result->status);
        $this->assertNotNull($result->payment);
        $this->assertEquals(Payment::STATUS_SETTLEMENT, $result->payment->status);
        $this->assertNotNull($result->payment->paid_at);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => Payment::STATUS_SETTLEMENT,
        ]);
    }

    public function test_cannot_mark_as_paid_if_not_pending(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PAID, 1);

        $this->expectException(LogicException::class);
        $this->service->markAsPaid($order, $this->admin->id);
    }

    public function test_mark_as_processing(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PAID, 2);

        $result = $this->service->markAsProcessing($order, $this->admin->id);

        $this->assertEquals(Order::STATUS_PROCESSING, $result->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_PAID,
            'to_status' => Order::STATUS_PROCESSING,
        ]);
    }

    public function test_cannot_mark_as_processing_if_not_paid(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 1);

        $this->expectException(LogicException::class);
        $this->service->markAsProcessing($order, $this->admin->id);
    }

    public function test_mark_as_shipped_requires_tracking_number_and_processing_status(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PROCESSING, 2);

        $result = $this->service->markAsShipped($order, 'JNE-8823910293', $this->admin->id);

        $this->assertEquals(Order::STATUS_SHIPPED, $result->status);
        $this->assertEquals('JNE-8823910293', $result->tracking_number);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_SHIPPED,
        ]);
    }

    public function test_cannot_mark_as_shipped_if_not_processing(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PAID, 1);

        $this->expectException(LogicException::class);
        $this->service->markAsShipped($order, 'JNE-8823910293', $this->admin->id);
    }

    public function test_mark_as_shipped_throws_exception_if_tracking_empty(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PROCESSING, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->service->markAsShipped($order, '   ', $this->admin->id);
    }

    public function test_mark_as_completed(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_SHIPPED, 1);

        $result = $this->service->markAsCompleted($order, $this->admin->id);

        $this->assertEquals(Order::STATUS_COMPLETED, $result->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_COMPLETED,
        ]);
    }

    public function test_cannot_mark_as_completed_if_not_shipped(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PROCESSING, 1);

        $this->expectException(LogicException::class);
        $this->service->markAsCompleted($order, $this->admin->id);
    }

    public function test_cancel_order_restores_stock_if_previously_paid(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 4);

        // Bayar dulu (stok berkurang dari 15 ke 11)
        $this->service->markAsPaid($order, $this->admin->id);
        $this->assertEquals(11, $this->sku->fresh()->stock);

        // Batalkan pesanan lunas
        $cancelledOrder = $this->service->cancelOrder($order, 'Buyer request refund sebelum dikirim', $this->admin->id);

        $this->assertEquals(Order::STATUS_CANCELLED, $cancelledOrder->status);
        $this->assertEquals('Buyer request refund sebelum dikirim', $cancelledOrder->cancelled_reason);

        // Stok harus kembali ke 15
        $this->assertEquals(15, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 4,
            'stock_before' => 11,
            'stock_after' => 15,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    public function test_cancel_order_without_prior_payment_does_not_duplicate_stock(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_PENDING, 2);

        $cancelled = $this->service->cancelOrder($order, 'Batal sebelum bayar', $this->admin->id);

        $this->assertEquals(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertEquals(15, $this->sku->fresh()->stock);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_id' => $order->id,
        ]);
    }

    public function test_cannot_cancel_shipped_order(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_SHIPPED, 1);

        $this->expectException(LogicException::class);
        $this->service->cancelOrder($order, 'Ingin batalkan padahal sudah di kurir', $this->admin->id);
    }

    public function test_update_tracking_number_records_audit(): void
    {
        $order = $this->createSampleOrder(Order::STATUS_SHIPPED, 1);
        $order->update(['tracking_number' => 'RESI-LAMA-001']);

        $updated = $this->service->updateTrackingNumber($order, 'RESI-BARU-002', $this->admin->id, 'Salah ketik nomor resi');

        $this->assertEquals('RESI-BARU-002', $updated->tracking_number);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_SHIPPED,
            'to_status' => Order::STATUS_SHIPPED,
            'note' => "Koreksi nomor resi dari 'RESI-LAMA-001' menjadi 'RESI-BARU-002'. Alasan: Salah ketik nomor resi",
        ]);
    }
}
