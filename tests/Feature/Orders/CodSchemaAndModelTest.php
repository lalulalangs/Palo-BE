<?php

namespace Tests\Feature\Orders;

use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CodSchemaAndModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_table_has_all_expected_cod_and_refund_columns(): void
    {
        $expectedColumns = [
            'payment_method',
            'cod_reference_code',
            'confirmed_at',
            'confirmed_by',
            'refund_status',
            'refund_reason',
            'refund_amount',
            'refund_method',
            'refund_reference',
            'refund_requested_at',
            'refund_approved_at',
            'refund_approved_by',
            'refunded_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('orders', $column),
                "Kolom {$column} wajib ada pada tabel orders."
            );
        }
    }

    public function test_order_model_constants_and_status_mappings(): void
    {
        // Status constants & labels
        $this->assertSame('awaiting_confirmation', Order::STATUS_AWAITING_CONFIRMATION);
        $this->assertSame('Menunggu Konfirmasi CS', Order::getStatusLabels()[Order::STATUS_AWAITING_CONFIRMATION]);
        $this->assertSame('warning', Order::getStatusColor(Order::STATUS_AWAITING_CONFIRMATION));

        // Payment method constants
        $this->assertSame('online', Order::PAYMENT_METHOD_ONLINE);
        $this->assertSame('cod', Order::PAYMENT_METHOD_COD);

        // Refund status constants & labels
        $this->assertSame('diajukan', Order::REFUND_STATUS_PENDING);
        $this->assertSame('disetujui', Order::REFUND_STATUS_APPROVED);
        $this->assertSame('ditolak', Order::REFUND_STATUS_REJECTED);
        $this->assertSame('selesai', Order::REFUND_STATUS_COMPLETED);

        $this->assertSame('Refund Diajukan', Order::getRefundStatusLabels()[Order::REFUND_STATUS_PENDING]);
        $this->assertSame('Refund Disetujui', Order::getRefundStatusLabels()[Order::REFUND_STATUS_APPROVED]);
        $this->assertSame('Refund Ditolak', Order::getRefundStatusLabels()[Order::REFUND_STATUS_REJECTED]);
        $this->assertSame('Refund Selesai', Order::getRefundStatusLabels()[Order::REFUND_STATUS_COMPLETED]);

        $this->assertSame('warning', Order::getRefundStatusColor(Order::REFUND_STATUS_PENDING));
        $this->assertSame('info', Order::getRefundStatusColor(Order::REFUND_STATUS_APPROVED));
        $this->assertSame('danger', Order::getRefundStatusColor(Order::REFUND_STATUS_REJECTED));
        $this->assertSame('success', Order::getRefundStatusColor(Order::REFUND_STATUS_COMPLETED));
        $this->assertSame('gray', Order::getRefundStatusColor(null));
    }

    public function test_order_frontend_status_returns_expected_snake_case(): void
    {
        $order = new Order(['status' => Order::STATUS_AWAITING_CONFIRMATION]);
        $this->assertSame('menunggu_konfirmasi_cs', $order->getFrontendStatus());
    }

    public function test_cod_reference_code_unique_constraint(): void
    {
        $user = User::factory()->create();

        Order::create([
            'order_number' => 'UJN-20261015-00001',
            'user_id' => $user->id,
            'status' => Order::STATUS_AWAITING_CONFIRMATION,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-20261015-00001',
            'total' => 100000,
        ]);

        $this->expectException(QueryException::class);

        // Duplicate cod_reference_code must fail
        Order::create([
            'order_number' => 'UJN-20261015-00002',
            'user_id' => $user->id,
            'status' => Order::STATUS_AWAITING_CONFIRMATION,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-20261015-00001',
            'total' => 150000,
        ]);
    }

    public function test_order_relations_for_confirmed_by_and_refund_approved_by(): void
    {
        $user = User::factory()->create();
        $admin1 = AdminUser::factory()->create(['email' => 'cs1@palorinjani.com']);
        $admin2 = AdminUser::factory()->create(['email' => 'supervisor@palorinjani.com']);

        $order = Order::create([
            'order_number' => 'UJN-20261015-00003',
            'user_id' => $user->id,
            'status' => Order::STATUS_PROCESSING,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-20261015-00003',
            'confirmed_at' => now(),
            'confirmed_by' => $admin1->id,
            'refund_status' => Order::REFUND_STATUS_APPROVED,
            'refund_approved_at' => now(),
            'refund_approved_by' => $admin2->id,
            'total' => 200000,
        ]);

        $this->assertInstanceOf(AdminUser::class, $order->confirmedBy);
        $this->assertSame($admin1->id, $order->confirmedBy->id);

        $this->assertInstanceOf(AdminUser::class, $order->refundApprovedBy);
        $this->assertSame($admin2->id, $order->refundApprovedBy->id);
    }

    public function test_order_helper_methods_for_cod_and_refund(): void
    {
        $user = User::factory()->create();

        $onlineOrder = Order::create([
            'order_number' => 'UJN-ONLINE-1',
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'total' => 100000,
        ]);

        $this->assertFalse($onlineOrder->isCod());
        $this->assertFalse($onlineOrder->canBeConfirmed());
        $this->assertFalse($onlineOrder->hasRefund());
        $this->assertFalse($onlineOrder->canBeRefunded());

        $codOrder = Order::create([
            'order_number' => 'UJN-COD-1',
            'user_id' => $user->id,
            'status' => Order::STATUS_AWAITING_CONFIRMATION,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-20261015-99999',
            'total' => 250000,
        ]);

        $this->assertTrue($codOrder->isCod());
        $this->assertTrue($codOrder->canBeConfirmed());
        $this->assertFalse($codOrder->hasRefund());
        $this->assertFalse($codOrder->canBeRefunded());

        // When COD order is completed, it can be refunded
        $codOrder->update(['status' => Order::STATUS_COMPLETED]);
        $this->assertTrue($codOrder->canBeRefunded());

        // When refund is requested
        $codOrder->update(['refund_status' => Order::REFUND_STATUS_PENDING]);
        $this->assertTrue($codOrder->hasRefund());
        $this->assertFalse($codOrder->canBeRefunded()); // Already has active refund

        // When refund is rejected, it can potentially be re-applied or reviewed
        $codOrder->update(['refund_status' => Order::REFUND_STATUS_REJECTED]);
        $this->assertTrue($codOrder->canBeRefunded());
    }

    public function test_default_app_settings_for_cod_in_database_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('true', AppSetting::getValue('cod_enabled'));
        $this->assertSame('081234567890', AppSetting::getValue('cod_cs_whatsapp_number'));
        $this->assertSame('24', AppSetting::getValue('cod_confirmation_timeout_hours'));
    }
}
