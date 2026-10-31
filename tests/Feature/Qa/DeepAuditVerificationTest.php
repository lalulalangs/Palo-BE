<?php

namespace Tests\Feature\Qa;

use App\Enums\AdminFeature;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\HeroSlider;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Payments\DokuWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeepAuditVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $authorizedAdmin;

    protected AdminUser $unauthorizedAdmin;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizedAdmin = AdminUser::factory()->create();

        $restrictedRole = Role::create([
            'name' => 'Staf Gudang Tanpa Akses Banner & Kategori',
            'permissions' => [AdminFeature::Products->value, AdminFeature::StockMovements->value],
        ]);
        $this->unauthorizedAdmin = AdminUser::factory()->create(['role_id' => $restrictedRole->id]);

        $this->customer = User::factory()->create();
    }

    /**
     * 1. Verifikasi rute alias /api/v1/admin/banners (CRUD & RBAC).
     */
    public function test_admin_banners_alias_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/banners')->assertUnauthorized();
        $this->postJson('/api/v1/admin/banners')->assertUnauthorized();
    }

    public function test_admin_banners_alias_rejects_customer_with_403(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/admin/banners')
            ->assertForbidden();
    }

    public function test_admin_banners_alias_rejects_admin_without_hero_sliders_permission(): void
    {
        $this->actingAs($this->unauthorizedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/banners')
            ->assertForbidden();
    }

    public function test_admin_banners_alias_crud_workflow(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('banner-promo.webp', 1200, 600);

        // CREATE
        $createResponse = $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Promo Musim Panas',
                'image_desktop' => $image,
                'primary_btn_url' => '/katalog/apparel',
                'primary_btn_text' => 'Beli Sekarang',
                'is_active' => true,
                'sort_order' => 1,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Promo Musim Panas');

        $bannerId = (int) $createResponse->json('data.id');

        // READ / INDEX
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/banners')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        // SHOW
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->getJson("/api/v1/admin/banners/{$bannerId}")
            ->assertOk()
            ->assertJsonPath('data.id', $bannerId)
            ->assertJsonPath('data.title', 'Promo Musim Panas');

        // UPDATE
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->putJson("/api/v1/admin/banners/{$bannerId}", [
                'title' => 'Promo Musim Hujan',
                'primary_btn_url' => '/katalog/raincoat',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Promo Musim Hujan');

        // DELETE
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/banners/{$bannerId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('hero_sliders', ['id' => $bannerId]);
    }

    /**
     * 2. Verifikasi Filament Category Resource RBAC & Table.
     */
    public function test_filament_category_page_restricted_by_rbac(): void
    {
        // Admin tanpa izin Categories ditolak (403)
        $this->actingAs($this->unauthorizedAdmin, 'admin')
            ->get('/admin/categories')
            ->assertForbidden();

        // Admin berwenang diizinkan (200)
        $category = Category::create([
            'name' => 'Peralatan Masak',
            'slug' => 'peralatan-masak',
        ]);

        $this->actingAs($this->authorizedAdmin, 'admin')
            ->get('/admin/categories')
            ->assertOk()
            ->assertSee('Peralatan Masak');
    }

    /**
     * 3. Cart Session & User Scoping Isolation (Anti-IDOR).
     */
    public function test_cart_items_isolated_between_different_users(): void
    {
        $category = Category::create(['name' => 'Carrier', 'slug' => 'carrier']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tas Carrier 60L',
            'slug' => 'tas-carrier-60l',
            'base_price' => 750000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Grey 60L']);
        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'CAR-60L-GRY',
            'stock' => 10,
            'is_active' => true,
        ]);

        // User A mengisi keranjang
        $userA = User::factory()->create();
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $sku->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.total_quantity', 2);

        // User B membuka keranjang -> wajib kosong (tidak boleh bocor dari User A)
        $userB = User::factory()->create();
        $responseUserB = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/v1/cart');

        $responseUserB->assertOk()
            ->assertJsonPath('data.summary.total_quantity', 0)
            ->assertJsonCount(0, 'data.items');
    }

    /**
     * 4. Voucher Edge Cases: Batas Nilai Minimum Pembelian (Boundary Test).
     */
    public function test_voucher_exact_minimum_purchase_boundary(): void
    {
        $voucher = Voucher::create([
            'code' => 'RINJANI100K',
            'type' => 'fixed',
            'value' => 25000,
            'min_purchase' => 100000,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7),
        ]);

        // Belanja Rp 99.999 (kurang Rp 1 dari batas minimum) -> Wajib Ditolak 422
        $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'RINJANI100K',
            'subtotal' => 99999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

        // Belanja Pas Rp 100.000 (batas minimum exact) -> Wajib Diterima 200
        $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'RINJANI100K',
            'subtotal' => 100000,
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'RINJANI100K')
        ->assertJsonPath('data.discount_amount', 25000)
        ->assertJsonPath('data.final_subtotal', 75000);
    }

    /**
     * 5. DOKU Webhook Idempotency: Notifikasi Ulang pada Pembayaran yang Sudah Settlement.
     */
    public function test_doku_webhook_handles_duplicate_notifications_idempotently(): void
    {
        $order = Order::create([
            'order_number' => 'UJN-AUDIT-20261031-01',
            'status' => OrderStatus::Paid,
            'subtotal' => 300000,
            'total' => 300000,
            'shipping_cost' => 0,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'doku',
            'payment_method' => 'qris',
            'amount' => 300000,
            'status' => Payment::STATUS_SETTLEMENT,
            'paid_at' => now()->subMinutes(5),
            'raw_response' => ['doku_channel' => 'qris'],
        ]);

        /** @var DokuWebhookService $webhookService */
        $webhookService = app(DokuWebhookService::class);

        // Simulasi applyStatus kedua kali dari event DOKU retry
        $applied = $webhookService->applyStatus(
            payment: $payment,
            status: 'SUCCESS',
            amount: 300000,
            channel: 'qris',
            source: 'webhook_retry'
        );

        // Harus mengembalikan false (tidak mengubah status ulang / tidak mencatat mutasi ganda)
        $this->assertFalse($applied);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_SETTLEMENT, $payment->status);
    }
}
