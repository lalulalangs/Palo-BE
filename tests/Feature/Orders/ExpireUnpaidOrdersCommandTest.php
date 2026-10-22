<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExpireUnpaidOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Expiry Buyer',
            'email' => 'expiry@example.test',
            'password_hash' => Hash::make('password123'),
        ]);
    }

    public function test_expired_pending_order_is_cancelled_and_voucher_quota_restored(): void
    {
        $voucher = Voucher::create([
            'code' => 'EXPIRY10',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'used_count' => 2,
            'is_active' => true,
        ]);

        $order = $this->createOrder(OrderStatus::Pending, Carbon::now()->subMinute(), $voucher->id);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'manual',
            'payment_method' => 'bank_transfer',
            'amount' => 100000,
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame(Payment::STATUS_EXPIRE, $payment->fresh()->status);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(
            'Pesanan kedaluwarsa otomatis (melewati batas waktu pembayaran).',
            $fresh->cancelled_reason
        );
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Cancelled->value,
        ]);
    }

    public function test_active_awaiting_confirmation_and_unlocked_orders_are_left_untouched(): void
    {
        $active = $this->createOrder(OrderStatus::Pending, Carbon::now()->addMinutes(10));
        $awaitingConfirmation = $this->createOrder(OrderStatus::AwaitingConfirmation, Carbon::now()->subMinute());
        $withoutLock = $this->createOrder(OrderStatus::Pending, null);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $active->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingConfirmation, $awaitingConfirmation->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $withoutLock->fresh()->status);
    }

    public function test_limit_option_caps_processed_orders(): void
    {
        $first = $this->createOrder(OrderStatus::Pending, Carbon::now()->subMinutes(5));
        $second = $this->createOrder(OrderStatus::Pending, Carbon::now()->subMinutes(4));

        $this->artisan('orders:expire-unpaid', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $second->fresh()->status);
    }

    private function createOrder(OrderStatus $status, ?Carbon $lockedUntil, ?int $voucherId = null): Order
    {
        return Order::create([
            'order_number' => 'UJN-20261019-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'voucher_id' => $voucherId,
            'status' => $status,
            'subtotal' => 100000,
            'total' => 100000,
            'stock_locked_until' => $lockedUntil,
        ]);
    }
}
