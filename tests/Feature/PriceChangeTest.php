<?php

namespace Tests\Feature;

use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Services\ShippingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test untuk deteksi perubahan harga.
 *
 * PRD §3.6 AC:
 *   "Given harga produk berubah antara buyer membuka cart dan menekan 'Bayar',
 *    When checkout diproses, Then sistem menggunakan harga terkini dari server
 *    dan menampilkan notifikasi perubahan harga ke buyer sebelum melanjutkan."
 *
 * Dua hal yang harus dibuktikan di sini, dan keduanya BERBEDA:
 *
 * 1. Keamanan — harga LAMA dari client TIDAK PERNAH dipakai menghitung total.
 *    Kalau ini gagal, penyerang bisa memesan barang seharga Rp 1.
 * 2. Kejujuran — buyer diberi tahu perubahannya, bukan diam-diam dikenai
 *    harga baru.
 */
class PriceChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Harga di cart tidak boleh dipakai. Order WAJIB memakai harga server.
     */
    public function test_total_selalu_menggunakan_harga_server(): void
    {
        $product = $this->makeProduct(price: 185000, stock: 10);

        // Buyer mengirim price_seen = 1.000.000 (harga saat cart dibuka).
        $result = $this->checkoutDanTangkap($product->skus()->first()->getKey(), priceSeen: 1000000);

        // Order TIDAK terbentuk karena harga berubah — dan itulah yang kita mau.
        $this->assertSame(
            'price_changed',
            $result['error_code'] ?? null,
            'Harga yang berbeda harus memicu notifikasi.',
        );

        // Total yang dilaporkan adalah total BERDASARKAN harga server (185.000),
        // bukan harga yang diklaim client (1.000.000).
        $this->assertSame(
            185000,
            $result['new_grand_total'] ?? null,
            'Total harus dihitung dari harga server, bukan harga kiriman client.',
        );
    }

    /**
     * Tidak ada order yang terbentuk saat harga berubah.
     *
     * Ini yang menjamin tidak ada order setengah jadi: exception dilempar dari
     * dalam transaksi, jadi semuanya rollback.
     */
    public function test_tidak_ada_order_terbentuk_saat_harga_berubah(): void
    {
        $product = $this->makeProduct(price: 185000, stock: 10);
        $ordersBefore = Order::count();

        $this->checkoutDanTangkap($product->skus()->first()->getKey(), priceSeen: 999);

        $this->assertSame($ordersBefore, Order::count(), 'Order tidak boleh terbentuk saat harga berubah.');
    }

    /**
     * Struktur `changes[]` harus lengkap supaya frontend bisa menampilkan
     * tabel "harga lama -> harga baru".
     */
    public function test_changes_menyimpan_harga_lama_dan_baru(): void
    {
        $product = $this->makeProduct(price: 200000, stock: 10);
        $sku = $product->skus()->first();

        $result = $this->checkoutDanTangkap($sku->getKey(), priceSeen: 150000);

        $this->assertCount(1, $result['changes'] ?? []);

        $change = $result['changes'][0];
        $this->assertSame($sku->getKey(), $change['sku_id']);
        $this->assertSame($sku->code, $change['sku_code']);
        $this->assertSame(150000, $change['old_price'], 'Harga lama = harga saat buyer membuka cart.');
        $this->assertSame(200000, $change['new_price'], 'Harga baru = harga server saat ini.');
    }

    /**
     * Harga yang TIDAK berubah tidak memicu notifikasi sama sekali.
     *
     * Kalau ini salah, checkout normal akan selalu tersendat di 409.
     */
    public function test_harga_yang_tetap_tidak_memicu_notifikasi(): void
    {
        $product = $this->makeProduct(price: 185000, stock: 10);
        $sku = $product->skus()->first();

        $result = $this->checkoutDanTangkap($sku->getKey(), priceSeen: 185000);

        $this->assertArrayNotHasKey(
            'error_code',
            $result,
            'Harga yang sama tidak boleh memicu 409.',
        );
    }

    /**
     * `price_seen` yang tidak dikirim tidak dianggap sebagai perubahan.
     *
     * Ini penting karena guest yang datang langsung ke checkout (tanpa
     * pernah membuka halaman keranjang) tidak punya harga "lama".
     */
    public function test_tanpa_price_seen_tidak_memicu_notifikasi(): void
    {
        $product = $this->makeProduct(price: 185000, stock: 10);

        $result = $this->checkoutDanTangkap($product->skus()->first()->getKey(), priceSeen: null);

        $this->assertArrayNotHasKey('error_code', $result);
    }

    /**
     * Helper: jalankan endpoint checkout, return body sebagai array.
     */
    private function checkoutDanTangkap(int $skuId, ?int $priceSeen): array
    {
        $parcels = [['weight_grams' => 500.0]];
        $hash = ShippingQuoteService::parcelsHash($parcels);
        Cache::put("palorinjani:shipping:cost:2761:DUMMY:REG:{$hash}", 0, 900);

        $item = ['sku_id' => $skuId, 'quantity' => 1];

        if ($priceSeen !== null) {
            $item['price_seen'] = $priceSeen;
        }

        $response = $this->postJson('/api/v1/checkout/orders', [
            'shipping_address' => [
                'recipient_name' => 'Budi',
                'phone' => '6281234567890',
                'province' => 'Lombok Utara',
                'city' => 'Mataram',
                'city_code' => '2761',
                'street' => 'Jl. Uji No. 1',
            ],
            'items' => [$item],
            'shipping_courier' => 'DUMMY',
            'shipping_service' => 'REG',
            'shipping_cost' => 0,
            'payment_method' => 'qris',
        ], $this->headerAutentikasi());

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Token Sanctum untuk user yang sudah login.
     */
    private function headerAutentikasi(): array
    {
        $user = $this->makeCustomer();

        return [
            'Authorization' => 'Bearer '.$user->createToken('uji')->plainTextToken,
        ];
    }
}
