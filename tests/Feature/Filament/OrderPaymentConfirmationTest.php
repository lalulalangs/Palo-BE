<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
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
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class OrderPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin.cs@palorinjani.com',
            'name' => 'Admin CS',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'buyer-confirm@palorinjani.com',
            'name' => 'Pembeli Senaru',
        ]);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kaos Trail Rinjani',
            'slug' => 'kaos-trail-rinjani',
            'base_price' => 200000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam / M',
            'attributes' => ['color' => 'Hitam', 'size' => 'M'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'KTR-HTM-M',
            'stock' => 8,
        ]);
    }

    protected function createPendingOrderWithPayment(int $qty = 1): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-PAY-'.fake()->unique()->numerify('######'),
            'user_id' => $this->buyer->id,
            'status' => OrderStatus::Pending,
            'subtotal' => 200000 * $qty,
            'discount_amount' => 0,
            'shipping_cost' => 14000,
            'total' => (200000 * $qty) + 14000,
            'shipping_recipient_name' => 'Pembeli Senaru',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Pendakian Senaru No. 7, Lombok Utara, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Kaos Trail Rinjani',
            'variant_name_snapshot' => 'Hitam / M',
            'sku_code_snapshot' => 'KTR-HTM-M',
            'price_snapshot' => 200000,
            'quantity' => $qty,
            'subtotal' => 200000 * $qty,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'manual',
            'payment_method' => 'bank_transfer',
            'amount' => $order->total,
            'status' => Payment::STATUS_PENDING,
            'expired_at' => $order->stock_locked_until,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'note' => 'Pesanan dibuat oleh pembeli.',
            'created_at' => now(),
        ]);

        return $order->fresh(['items']);
    }

    public function test_admin_can_confirm_payment_of_pending_order(): void
    {
        $order = $this->createPendingOrderWithPayment();
        $order->payment->update(['gateway_transaction_id' => 'BCVA-99228811']);
        $initialStock = $this->sku->fresh()->stock;

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('mark_as_paid')
            ->callAction('mark_as_paid')
            ->assertHasNoActionErrors()
            ->assertNotified();

        // 1. Status order: Pending -> Paid
        $this->assertEquals(OrderStatus::Paid, $order->fresh()->status);

        // 2. Status payment: pending -> settlement (+ paid_at terisi)
        $payment = $order->payment()->firstOrFail();
        $this->assertSame(Payment::STATUS_SETTLEMENT, $payment->status);
        $this->assertNotNull($payment->paid_at);

        // 3. Stok fisik berkurang via StockMovement TYPE_SALE
        $this->assertEquals($initialStock - 1, $this->sku->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_SALE,
            'quantity_change' => -1,
            'user_id' => $this->admin->id,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);

        // 4. Riwayat status tercatat dengan jejak audit dari data payment
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Paid->value,
            'changed_by_admin_id' => $this->admin->id,
        ]);

        $history = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->where('to_status', OrderStatus::Paid->value)
            ->firstOrFail();
        $this->assertStringContainsString('Pembayaran dikonfirmasi manual oleh admin.', $history->note);
        $this->assertStringContainsString('Gateway: manual', $history->note);
        $this->assertStringContainsString('status: pending', $history->note);
        $this->assertStringContainsString('invoice: BCVA-99228811', $history->note);

        // 5. Tombol langkah berikutnya ("Proses Pesanan") aktif pada status Paid
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('mark_as_processing')
            ->assertActionHidden('mark_as_paid');
    }

    public function test_mark_as_paid_action_hidden_for_non_pending_statuses(): void
    {
        foreach ([OrderStatus::AwaitingConfirmation, OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
            $order = $this->createPendingOrderWithPayment();
            $order->update(['status' => $status]);

            Livewire::actingAs($this->admin, 'admin')
                ->test(ViewOrder::class, ['record' => $order->getKey()])
                ->assertActionHidden('mark_as_paid');
        }
    }

    public function test_service_rejects_mark_as_paid_when_order_is_not_pending(): void
    {
        $order = $this->createPendingOrderWithPayment();
        $order->update(['status' => OrderStatus::Paid]);

        $this->expectException(LogicException::class);

        app(OrderTransitionService::class)->markAsPaid($order->fresh(['items']), $this->admin->id);
    }
}
