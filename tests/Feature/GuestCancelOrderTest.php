<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCancelOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Order $order;

    protected string $referenceCode = 'GC-20261027-TEST';

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kaos Rinjani',
            'slug' => 'kaos-rinjani',
            'base_price' => 150000,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Size M',
        ]);

        Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'KS-RNJ-M',
            'stock' => 20,
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'order_number' => 'PLR-20261027-99999',
            'user_id' => null,
            'status' => Order::STATUS_AWAITING_CONFIRMATION,
            'payment_method' => Order::PAYMENT_METHOD_WHATSAPP,
            'guest_reference_code' => $this->referenceCode,
            'subtotal' => 150000,
            'shipping_cost' => 0,
            'total' => 150000,
            'shipping_recipient_name' => 'Tamu Pembatal',
            'shipping_phone' => '081234567890',
        ]);

        Payment::create([
            'order_id' => $this->order->id,
            'gateway' => 'whatsapp',
            'payment_method' => Order::PAYMENT_METHOD_WHATSAPP,
            'amount' => 150000,
            'status' => Payment::STATUS_PENDING,
            'expired_at' => now()->addHours(24),
        ]);
    }

    public function test_guest_can_cancel_unconfirmed_whatsapp_order_with_valid_reference_code(): void
    {
        $response = $this->postJson('/api/v1/guest/cancel-order', [
            'order_number' => $this->order->order_number,
            'reference_code' => $this->referenceCode,
            'reason' => 'Ingin mengganti ukuran',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Pesanan berhasil dibatalkan.',
            ]);

        $this->order->refresh();
        $this->assertEquals(OrderStatus::Cancelled, $this->order->status);
        $this->assertSame('Ingin mengganti ukuran', $this->order->cancelled_reason);

        // Pastikan payment juga tercatat cancel
        $this->assertSame(Payment::STATUS_CANCEL, $this->order->payment->status);

        // Pastikan status history tercatat
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $this->order->id,
            'to_status' => OrderStatus::Cancelled->value,
        ]);
    }

    public function test_guest_cannot_cancel_order_with_invalid_reference_code(): void
    {
        $response = $this->postJson('/api/v1/guest/cancel-order', [
            'order_number' => $this->order->order_number,
            'reference_code' => 'GC-SALAH-KODE',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Kode referensi pesanan tidak sesuai.',
            ]);

        $this->order->refresh();
        $this->assertEquals(OrderStatus::AwaitingConfirmation, $this->order->status);
    }

    public function test_guest_cannot_cancel_non_existent_order(): void
    {
        $response = $this->postJson('/api/v1/guest/cancel-order', [
            'order_number' => 'PLR-NON-EXISTENT',
            'reference_code' => $this->referenceCode,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Pesanan tidak ditemukan.',
            ]);
    }

    public function test_guest_cannot_cancel_order_that_is_already_processing(): void
    {
        $this->order->update(['status' => OrderStatus::Processing]);

        $response = $this->postJson('/api/v1/guest/cancel-order', [
            'order_number' => $this->order->order_number,
            'reference_code' => $this->referenceCode,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Pesanan tidak dalam status menunggu konfirmasi sehingga tidak dapat dibatalkan.',
            ]);
    }
}
