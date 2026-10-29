<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderReconciliationFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Reconciliation Tester',
            'email' => 'reconciliation@example.test',
            'password_hash' => Hash::make('password123'),
        ]);
    }

    public function test_pending_order_with_pending_payment_does_not_need_reconciliation(): void
    {
        $order = $this->createOrder(Order::STATUS_PENDING);
        $this->createPayment($order, Payment::STATUS_PENDING);

        $this->assertFalse($order->fresh()->needsReconciliation());
    }

    public function test_settled_payment_on_pending_order_needs_reconciliation(): void
    {
        $order = $this->createOrder(Order::STATUS_PENDING);
        $this->createPayment($order, Payment::STATUS_SETTLEMENT);

        $this->assertTrue($order->fresh()->needsReconciliation());
    }

    public function test_expired_payment_on_pending_order_needs_reconciliation(): void
    {
        $order = $this->createOrder(Order::STATUS_PENDING);
        $this->createPayment($order, Payment::STATUS_EXPIRE);

        $this->assertTrue($order->fresh()->needsReconciliation());
    }

    public function test_expired_payment_on_cancelled_order_does_not_need_reconciliation(): void
    {
        $order = $this->createOrder(Order::STATUS_CANCELLED);
        $this->createPayment($order, Payment::STATUS_EXPIRE);

        $this->assertFalse($order->fresh()->needsReconciliation());
    }

    private function createOrder(string $status): Order
    {
        return Order::create([
            'order_number' => 'UJN-20261018-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'user_id' => $this->user->id,
            'status' => $status,
            'subtotal' => 100000,
            'total' => 100000,
        ]);
    }

    private function createPayment(Order $order, string $status): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'gateway' => 'doku',
            'payment_method' => 'qris',
            'amount' => 100000,
            'status' => $status,
        ]);
    }
}
