<?php

namespace Tests\Feature\Orders;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CodOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected OrderTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create(['email' => 'cs-admin@palorinjani.com']);
        $this->buyer = User::factory()->create(['email' => 'buyer-cod@example.com']);
        $this->service = app(OrderTransitionService::class);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kemeja Flannel Rinjani',
            'slug' => 'kemeja-flannel-rinjani',
            'base_price' => 250000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy Plaid - XL',
            'attributes' => ['color' => 'Navy', 'size' => 'XL'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PALO-FLN-NVY-XL',
            'stock' => 10,
        ]);
    }

    protected function createCodOrder(
        string $status = Order::STATUS_AWAITING_CONFIRMATION,
        int $qty = 2,
        ?int $voucherId = null
    ): Order {
        $order = Order::create([
            'order_number' => 'COD-'.date('Ymd').'-'.fake()->unique()->numerify('#####'),
            'user_id' => $this->buyer->id,
            'voucher_id' => $voucherId,
            'status' => $status,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-REF-'.fake()->unique()->numerify('#####'),
            'subtotal' => 250000 * $qty,
            'discount_amount' => $voucherId ? 25000 : 0,
            'shipping_cost' => 15000,
            'total' => (250000 * $qty) + 15000 - ($voucherId ? 25000 : 0),
            'shipping_recipient_name' => 'Ahmad Dani',
            'shipping_phone' => '087812345678',
            'shipping_full_address' => 'Jl. Pariwisata Kuta Mandalika, Lombok Tengah, NTB',
            'shipping_courier' => 'J&T',
            'shipping_service' => 'EZ',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Kemeja Flannel Rinjani',
            'variant_name_snapshot' => 'Navy Plaid - XL',
            'sku_code_snapshot' => 'PALO-FLN-NVY-XL',
            'price_snapshot' => 250000,
            'quantity' => $qty,
            'subtotal' => 250000 * $qty,
        ]);

        return $order;
    }

    public function test_cs_can_confirm_cod_order_and_deduct_warehouse_stock(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 3);
        $this->assertEquals(10, $this->sku->fresh()->stock);

        $confirmed = $this->service->confirmCodOrder(
            $order,
            $this->admin->id,
            'Telah dikonfirmasi dengan customer via WhatsApp.'
        );

        $this->assertEquals(Order::STATUS_PROCESSING, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertEquals($this->admin->id, $confirmed->confirmed_by);

        // Stok fisik gudang berkurang dari 10 menjadi 7
        $this->assertEquals(7, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_SALE,
            'quantity_change' => -3,
            'stock_before' => 10,
            'stock_after' => 7,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'changed_by_admin_id' => $this->admin->id,
            'from_status' => Order::STATUS_AWAITING_CONFIRMATION,
            'to_status' => Order::STATUS_PROCESSING,
            'note' => 'Telah dikonfirmasi dengan customer via WhatsApp.',
        ]);
    }

    public function test_cannot_confirm_non_cod_order(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 1);
        $order->update(['payment_method' => Order::PAYMENT_METHOD_ONLINE]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('bukan merupakan pesanan COD');
        $this->service->confirmCodOrder($order, $this->admin->id);
    }

    public function test_cannot_confirm_cod_order_if_not_awaiting_confirmation(): void
    {
        $order = $this->createCodOrder(Order::STATUS_PROCESSING, 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak dalam status menunggu konfirmasi CS');
        $this->service->confirmCodOrder($order, $this->admin->id);
    }

    public function test_confirm_cod_order_fails_if_warehouse_stock_is_insufficient(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 15); // Stok cuma 10

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stok SKU PALO-FLN-NVY-XL tidak mencukupi');
        $this->service->confirmCodOrder($order, $this->admin->id);
    }

    public function test_cancel_unconfirmed_cod_order_does_not_mutate_stock(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);
        $this->assertEquals(10, $this->sku->fresh()->stock);

        $cancelled = $this->service->cancelOrder(
            $order,
            'Customer minta batal lewat WhatsApp',
            $this->admin->id
        );

        $this->assertEquals(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertEquals('Customer minta batal lewat WhatsApp', $cancelled->cancelled_reason);

        // Stok tidak berubah karena saat awaiting confirmation stok belum dipotong
        $this->assertEquals(10, $this->sku->fresh()->stock);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_id' => $order->id,
        ]);
    }

    public function test_cancel_confirmed_cod_order_restores_stock(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 3);
        $this->service->confirmCodOrder($order, $this->admin->id);

        $this->assertEquals(7, $this->sku->fresh()->stock);

        // Batalkan setelah status diproses
        $cancelled = $this->service->cancelOrder(
            $order,
            'Barang cacat sebelum kirim dan customer ingin cancel',
            $this->admin->id
        );

        $this->assertEquals(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertEquals(10, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 3,
            'stock_before' => 7,
            'stock_after' => 10,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    public function test_cancel_cod_order_restores_voucher_quota(): void
    {
        $voucher = Voucher::create([
            'code' => 'CODPROMO',
            'type' => Voucher::TYPE_FIXED,
            'value' => 25000,
            'min_purchase' => 100000,
            'quota' => 10,
            'used_count' => 5,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'is_active' => true,
        ]);

        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2, $voucher->id);
        $this->assertEquals(5, $voucher->fresh()->used_count);

        $this->service->cancelOrder($order, 'Batal via CS', $this->admin->id);

        $this->assertEquals(4, $voucher->fresh()->used_count);
    }

    public function test_expire_unconfirmed_cod_order(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);

        $expired = $this->service->expireOrder($order);

        $this->assertEquals(Order::STATUS_EXPIRED, $expired->status);
        $this->assertEquals('Batas waktu konfirmasi CS telah habis.', $expired->cancelled_reason);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_AWAITING_CONFIRMATION,
            'to_status' => Order::STATUS_EXPIRED,
        ]);
    }

    public function test_mark_as_completed_settles_cod_payment(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);
        $this->service->confirmCodOrder($order, $this->admin->id);
        $this->service->markAsShipped($order, 'JNT123456789', $this->admin->id);

        $completed = $this->service->markAsCompleted($order, $this->admin->id, 'Pelanggan telah menerima barang dan bayar tunai.');

        $this->assertEquals(Order::STATUS_COMPLETED, $completed->status);

        // Payment record otomatis terselesaikan (settlement)
        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('cod', $payment->gateway);
        $this->assertEquals('cod', $payment->payment_method);
        $this->assertEquals(Payment::STATUS_SETTLEMENT, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertEquals($order->total, $payment->amount);
    }

    public function test_full_cod_refund_flow_with_stock_restoration(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);
        $this->service->confirmCodOrder($order, $this->admin->id);
        $this->service->markAsShipped($order, 'JNT999999999', $this->admin->id);
        $this->service->markAsCompleted($order, $this->admin->id);

        // Stok saat ini 8 (10 - 2)
        $this->assertEquals(8, $this->sku->fresh()->stock);

        // 1. Request Refund
        $refundedOrder = $this->service->requestRefund(
            $order,
            (float) $order->total,
            'Baju sobek pada bagian jahitan ketiak',
            'https://storage.palorinjani.com/evidence/sobek.jpg'
        );

        $this->assertEquals(Order::REFUND_STATUS_PENDING, $refundedOrder->refund_status);
        $this->assertEquals($order->total, $refundedOrder->refund_amount);
        $this->assertEquals('Baju sobek pada bagian jahitan ketiak', $refundedOrder->refund_reason);
        $this->assertNotNull($refundedOrder->refund_requested_at);

        // 2. Approve Refund
        $approvedOrder = $this->service->approveRefund($refundedOrder, $this->admin->id, 'Disetujui untuk retur');
        $this->assertEquals(Order::REFUND_STATUS_APPROVED, $approvedOrder->refund_status);
        $this->assertEquals($this->admin->id, $approvedOrder->refund_approved_by);
        $this->assertNotNull($approvedOrder->refund_approved_at);

        // 3. Complete Refund (dengan pengembalian stok barang retur)
        $completedRefund = $this->service->completeRefund(
            $approvedOrder,
            'TRF-BCA-20261015-88219',
            restoreStock: true,
            actorId: $this->admin->id,
            method: 'transfer_bca',
            note: 'Dana sudah ditransfer balik dan barang retur telah masuk gudang.'
        );

        $this->assertEquals(Order::REFUND_STATUS_COMPLETED, $completedRefund->refund_status);
        $this->assertEquals('TRF-BCA-20261015-88219', $completedRefund->refund_reference);
        $this->assertEquals('transfer_bca', $completedRefund->refund_method);
        $this->assertNotNull($completedRefund->refunded_at);

        // Stok kembali ke 10
        $this->assertEquals(10, $this->sku->fresh()->stock);

        // Payment diupdate ke status refund
        $this->assertEquals(Payment::STATUS_REFUND, $completedRefund->payment->status);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 2,
            'stock_before' => 8,
            'stock_after' => 10,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    public function test_refund_rejection_allows_resubmission(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 1);
        $this->service->confirmCodOrder($order, $this->admin->id);
        $this->service->markAsShipped($order, 'JNT888888888', $this->admin->id);
        $this->service->markAsCompleted($order, $this->admin->id);

        $this->service->requestRefund($order, (float) $order->total, 'Tidak cocok warnanya');
        $rejectedOrder = $this->service->rejectRefund($order, 'Alasan tidak memenuhi syarat garansi', $this->admin->id);

        $this->assertEquals(Order::REFUND_STATUS_REJECTED, $rejectedOrder->refund_status);

        // Customer dapat mengajukan ulang dengan bukti baru
        $resubmitted = $this->service->requestRefund(
            $rejectedOrder,
            (float) $order->total,
            'Warna berbeda jauh dari katalog dan jahitan lepas',
            'https://storage.palorinjani.com/evidence/warna.jpg'
        );

        $this->assertEquals(Order::REFUND_STATUS_PENDING, $resubmitted->refund_status);
        $this->assertEquals('Warna berbeda jauh dari katalog dan jahitan lepas', $resubmitted->refund_reason);
    }

    public function test_cannot_request_refund_on_uncompleted_order(): void
    {
        $order = $this->createCodOrder(Order::STATUS_PROCESSING, 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('hanya dapat diajukan untuk pesanan yang sudah berstatus selesai');
        $this->service->requestRefund($order, 100000, 'Ingin refund');
    }

    public function test_cannot_request_refund_with_amount_exceeding_order_total(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 1);
        $this->service->confirmCodOrder($order, $this->admin->id);
        $this->service->markAsShipped($order, 'JNT777777777', $this->admin->id);
        $this->service->markAsCompleted($order, $this->admin->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh melebihi total pesanan');
        $this->service->requestRefund($order, (float) $order->total + 50000, 'Lebih dari total');
    }
}
