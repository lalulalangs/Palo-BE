<?php

namespace Tests\Feature\Filament;

use App\Enums\AdminFeature;
use App\Enums\OrderStatus;
use App\Events\Orders\OrderAwaitingConfirmation;
use App\Events\Orders\OrderPaid;
use App\Filament\Resources\Orders\OrderResource;
use App\Listeners\SendNewOrderAdminNotification;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Sku;
use App\Models\User;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $superAdmin;
    protected AdminUser $orderAdmin;
    protected AdminUser $inactiveOrderAdmin;
    protected AdminUser $contentAdmin;
    protected Order $sampleOrder;
    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Super Admin Aktif
        $this->superAdmin = AdminUser::factory()->superAdmin()->create([
            'is_active' => true,
            'name' => 'Super Administrator',
            'email' => 'super@palorinjani.com',
        ]);

        // 2. Admin dengan Role Pesanan Aktif
        $orderRole = Role::factory()->create([
            'name' => 'Order Specialist',
            'permissions' => [AdminFeature::Orders->value],
            'is_super_admin' => false,
        ]);
        $this->orderAdmin = AdminUser::factory()->create([
            'role_id' => $orderRole->id,
            'is_active' => true,
            'name' => 'Admin Order Aktif',
            'email' => 'order.staff@palorinjani.com',
        ]);

        // 3. Admin dengan Role Pesanan TETAPI Non-Aktif
        $this->inactiveOrderAdmin = AdminUser::factory()->create([
            'role_id' => $orderRole->id,
            'is_active' => false,
            'name' => 'Mantan Admin Order',
            'email' => 'inactive.staff@palorinjani.com',
        ]);

        // 4. Admin tanpa akses Pesanan (Hanya Banner / Konten)
        $bannerRole = Role::factory()->create([
            'name' => 'Content Creator',
            'permissions' => [AdminFeature::Banners->value],
            'is_super_admin' => false,
        ]);
        $this->contentAdmin = AdminUser::factory()->create([
            'role_id' => $bannerRole->id,
            'is_active' => true,
            'name' => 'Staff Banner',
            'email' => 'content@palorinjani.com',
        ]);

        // Sample Catalog Setup for stock deductions
        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel-test']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Rinjani Pro',
            'slug' => 'jaket-rinjani-pro',
            'base_price' => 500000,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam - L',
            'attributes' => ['color' => 'Hitam', 'size' => 'L'],
        ]);
        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JKT-RINJ-BLK-L',
            'stock' => 10,
            'is_active' => true,
        ]);

        // Sample Base Order
        $buyer = User::factory()->create();
        $this->sampleOrder = Order::create([
            'order_number' => 'PRJ-20261109-00001',
            'user_id' => $buyer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 500000,
            'discount_amount' => 0,
            'shipping_cost' => 30000,
            'total' => 530000,
            'shipping_recipient_name' => 'Pendaki Lawang',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Sembalun, Lombok Timur',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'stock_locked_until' => now()->addMinutes(60),
        ]);

        OrderItem::create([
            'order_id' => $this->sampleOrder->id,
            'sku_id' => $this->sku->id,
            'quantity' => 1,
            'price_snapshot' => 500000,
            'subtotal' => 500000,
            'sku_code_snapshot' => 'JKT-RINJ-BLK-L',
            'product_name_snapshot' => 'Jaket Rinjani Pro',
            'variant_name_snapshot' => 'Hitam - L',
        ]);

        Payment::create([
            'order_id' => $this->sampleOrder->id,
            'gateway' => 'doku',
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'amount' => 530000,
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    /**
     * @test
     * Order Online berstatus pending WAJIB 100% silent (tidak ada event/notifikasi).
     */
    public function test_online_order_pending_is_strictly_silent(): void
    {
        Event::fake([OrderPaid::class, OrderAwaitingConfirmation::class]);

        $this->assertSame(OrderStatus::Pending, $this->sampleOrder->status);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderAwaitingConfirmation::class);
        $this->assertDatabaseEmpty('notifications');
    }

    /**
     * @test
     * OrderPaid ter-dispatch saat markAsPaid dijalankan dan memicu listener terdaftar.
     */
    public function test_order_paid_event_triggers_queued_listener(): void
    {
        Event::fake([OrderPaid::class]);

        $transitionService = app(OrderTransitionService::class);
        $transitionService->markAsPaid($this->sampleOrder, null, 'DOKU settlement test');

        $this->assertSame(OrderStatus::Paid, $this->sampleOrder->fresh()->status);

        Event::assertDispatched(OrderPaid::class, function ($event) {
            return $event->order->id === $this->sampleOrder->id;
        });
    }

    /**
     * @test
     * OrderAwaitingConfirmation ter-dispatch saat order COD dibuat.
     */
    public function test_order_awaiting_confirmation_event_dispatched(): void
    {
        Event::fake([OrderAwaitingConfirmation::class]);

        $codOrder = Order::create([
            'order_number' => 'PRJ-COD-20261109-00002',
            'user_id' => null,
            'status' => OrderStatus::AwaitingConfirmation,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'subtotal' => 200000,
            'shipping_cost' => 20000,
            'total' => 220000,
            'shipping_recipient_name' => 'Budi Santoso',
            'shipping_phone' => '087712345678',
            'shipping_full_address' => 'Mataram, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        OrderAwaitingConfirmation::dispatch($codOrder);

        Event::assertDispatched(OrderAwaitingConfirmation::class, function ($event) use ($codOrder) {
            return $event->order->id === $codOrder->id;
        });
    }

    /**
     * @test
     * Notifikasi hanya dikirim ke admin aktif yang memiliki fitur Orders atau Super Admin.
     */
    public function test_notification_recipients_strict_authorization_filtering(): void
    {
        $listener = app(SendNewOrderAdminNotification::class);
        $listener->handle(new OrderPaid($this->sampleOrder));

        // Verifikasi tabel database 'notifications'
        // Admin yang berhak: Super Admin & Admin Order Aktif
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_id' => $this->superAdmin->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_id' => $this->orderAdmin->id,
        ]);

        // Admin yang DITOLAK: Admin Non-Aktif & Admin Tanpa Akses Order
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_id' => $this->inactiveOrderAdmin->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_id' => $this->contentAdmin->id,
        ]);
    }

    /**
     * @test
     * Struktur payload JSON kolom 'data' di database notifications sesuai kontrak Filament v3.
     */
    public function test_notification_database_payload_conforms_to_contract(): void
    {
        $listener = app(SendNewOrderAdminNotification::class);
        $listener->handle(new OrderPaid($this->sampleOrder));

        $record = DB::table('notifications')
            ->where('notifiable_type', AdminUser::class)
            ->where('notifiable_id', $this->orderAdmin->id)
            ->first();

        $this->assertNotNull($record, 'Record notifikasi wajib tersimpan di database.');

        $data = json_decode($record->data, true);

        // Verifikasi Title & Body
        $this->assertStringContainsString($this->sampleOrder->order_number, $data['title']);
        $this->assertStringContainsString('Rp', $data['body']);
        $this->assertSame('heroicon-o-check-circle', $data['icon']);
        $this->assertSame('success', $data['iconColor']);

        // Verifikasi Actions
        $this->assertArrayHasKey('actions', $data);
        $actions = $data['actions'];
        $this->assertNotEmpty($actions);

        // Action 1: Buka Pesanan URL mengarah ke OrderResource view
        $expectedUrl = OrderResource::getUrl('view', ['record' => $this->sampleOrder]);
        $viewAction = collect($actions)->firstWhere('name', 'view_order');
        $this->assertNotNull($viewAction, 'Action view_order wajib ada.');
        $this->assertSame($expectedUrl, $viewAction['url']);

        // Action 2: Chat WhatsApp URL
        $waAction = collect($actions)->firstWhere('name', 'chat_whatsapp');
        $this->assertNotNull($waAction, 'Action chat_whatsapp wajib ada.');
        $this->assertStringStartsWith('https://wa.me/6281234567890', $waAction['url']);
        $this->assertTrue($waAction['shouldOpenUrlInNewTab'] ?? true);
    }

    /**
     * @test
     * Akses URL order view diblokir (403) jika diakses oleh admin tanpa izin Orders.
     */
    public function test_unauthorized_admin_cannot_access_order_view_from_notification_action(): void
    {
        $viewUrl = OrderResource::getUrl('view', ['record' => $this->sampleOrder]);

        // 1. Staff Banner (tanpa izin Orders) mencoba membuka link dari notifikasi
        $response = $this->actingAs($this->contentAdmin, 'admin')->get($viewUrl);
        $response->assertForbidden(); // HTTP 403

        // 2. Staff Order Aktif membuka link
        $responseOrderStaff = $this->actingAs($this->orderAdmin, 'admin')->get($viewUrl);
        $responseOrderStaff->assertSuccessful(); // HTTP 200
    }

    /**
     * @test
     * Idempotency: Webhook retry DOKU ganda tidak menghasilkan 2 notifikasi pada order yang sama.
     */
    public function test_listener_is_strictly_idempotent_against_webhook_retries(): void
    {
        $listener = app(SendNewOrderAdminNotification::class);
        $event = new OrderPaid($this->sampleOrder);

        // Eksekusi pertama
        $listener->handle($event);
        $countInitial = DB::table('notifications')
            ->where('notifiable_id', $this->orderAdmin->id)
            ->count();
        $this->assertSame(1, $countInitial);

        // Eksekusi kedua (simulasi webhook retry)
        $listener->handle($event);
        $countAfterRetry = DB::table('notifications')
            ->where('notifiable_id', $this->orderAdmin->id)
            ->count();

        $this->assertSame(1, $countAfterRetry, 'Duplikasi notifikasi terdeteksi saat retry webhook!');
    }

    /**
     * @test
     * Format HP customer null atau invalid menghasilkan graceful degradation (tanpa action chat_whatsapp).
     */
    public function test_phone_sanitization_and_graceful_omission(): void
    {
        $orderNoPhone = Order::create([
            'order_number' => 'PRJ-NOPHONE-001',
            'user_id' => null,
            'status' => OrderStatus::AwaitingConfirmation,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'subtotal' => 100000,
            'shipping_cost' => 15000,
            'total' => 115000,
            'shipping_recipient_name' => 'Anonim',
            'shipping_phone' => null,
            'shipping_full_address' => 'Sembalun',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        $listener = app(SendNewOrderAdminNotification::class);
        $listener->handle(new OrderAwaitingConfirmation($orderNoPhone));

        $record = DB::table('notifications')
            ->where('notifiable_type', AdminUser::class)
            ->where('notifiable_id', $this->orderAdmin->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($record);
        $data = json_decode($record->data, true);
        $actions = $data['actions'];

        // Action verify_order harus tetap ada
        $verifyAction = collect($actions)->firstWhere('name', 'verify_order');
        $this->assertNotNull($verifyAction);

        // Action chat_whatsapp harus ditiadakan secara aman karena nomor null
        $waAction = collect($actions)->firstWhere('name', 'chat_whatsapp');
        $this->assertNull($waAction);
    }
}
