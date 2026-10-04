<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Exceptions\PriceChangedException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Services\OrderNumberGenerator;
use App\Domain\Checkout\Services\OrderPricingService;
use App\Domain\Checkout\Services\ShippingQuoteService;
use App\Domain\Inventory\Actions\ReleaseReservation;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Exceptions\GatewayException;
use App\Domain\Voucher\Actions\ApplyVoucher;
use App\Domain\Voucher\Models\Voucher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membuat order dari keranjang.
 *
 * PRD §3.6 AC: "Given seluruh data checkout valid, When buyer menekan 'Buat
 * Pesanan', Then order tersimpan dengan snapshot lengkap dan reservasi per SKU
 * tercatat ATOMIK; stok fisik berkurang sekali setelah pembayaran terverifikasi."
 *
 * PRD §3.12 AC: "Given request checkout dikirim dengan harga yang dimanipulasi
 * dari client, When diproses backend, Then sistem mengabaikan harga dari client
 * dan menggunakan harga tervalidasi dari database."
 *
 * ===================================================================
 *  URUTAN TRANSAKSI — JANGAN DIACAK
 * ===================================================================
 *   FASE 1 (dalam transaksi DB):
 *     a. Re-validasi SEMUA dari server: SKU aktif, harga, voucher, alamat.
 *     b. Hitung total dari `skus.price`. Nilai client DIBUANG.
 *     c. Snapshot item (nama, varian, harga) — immutable.
 *     d. Kunci stok per SKU (ReserveStock, row-level lock).
 *     e. Konsumsi kuota voucher secara atomik.
 *     f. Insert order_status_histories (aktor = customer).
 *     COMMIT
 *
 *   FASE 2 (di luar transaksi):
 *     g. Panggil payment gateway dengan idempotency key.
 *
 * Kenapa FASE 2 di luar transaksi? Karena panggilan HTTP tidak boleh menahan transaksi database. Kalau gateway lambat 30 detik, seluruh baris
 * skus ikut terkunci dan seluruh checkout jadi lambat.
 *
 * Kalau FASE 2 gagal: order dibatalkan & reservasi dilepas (lihat
 * handleGatewayFailure). Langkah ini harus AMAN DIULANG karena client bisa
 * klik tombol "Bayar" lagi.
 */
class CreateOrder
{
    public function __construct(
        private readonly OrderNumberGenerator $numberGenerator,
        private readonly OrderPricingService $pricing,
        private readonly ReserveStock $reserveStock,
        private readonly ApplyVoucher $applyVoucher,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @param  array{
     *     shipping_address: array<string, mixed>,
     *     items: array<int, array{sku_id: int, quantity: int}>,
     *     shipping_courier?: string|null,
     *     shipping_service?: string|null,
     *     shipping_cost?: int,
     *     shipping_etd?: string|null,
     *     payment_method: string,
     *     voucher_code?: string|null
     * }  $data
     */
    public function execute(User $user, array $data): Order
    {
        $orderNumber = $this->numberGenerator->generate();
        $addressSnapshot = $this->buildAddressSnapshot($data['shipping_address']);

        // ------------------------------------------------------------------
        // FASE 1 — transaksi atomik
        // ------------------------------------------------------------------
        $order = DB::transaction(function () use ($user, $data, $orderNumber, $addressSnapshot) {
            // 1. Muat SKU dari server. HANYA SKU id & quantity yang dipercaya
            //    dari client; semua field lain diambil dari database.
            $skuIds = array_column($data['items'], 'sku_id');
            $skus = Sku::whereIn('id', $skuIds)
                ->where('is_active', true)
                ->with('product', 'variant')
                ->get()
                ->keyBy('id');

            if ($skus->count() !== count($skuIds)) {
                throw InsufficientStockException::skuUnavailable();
            }

            // 2. Susun baris item dari snapshot server-side.
            $items = [];
            $priceChanges = [];
            $parcelsInput = [];
            foreach ($data['items'] as $item) {
                $sku = $skus[$item['sku_id']] ?? null;

                if ($sku === null) {
                    throw InsufficientStockException::skuUnavailable();
                }

                $quantity = max(1, (int) $item['quantity']);
                $unitPrice = (int) $sku->price; // <- HARGA DARI DB, bukan dari client

                $parcelsInput[] = [
                    'weight_grams' => (float) ($sku->weight_grams ?? 0),
                    'quantity' => $quantity,
                ];

                // ------------------------------------------------------------------
                // Deteksi perubahan harga (PRD §3.6 AC)
                //
                // "Given harga produk berubah antara buyer membuka cart dan
                //  menekan 'Bayar', When checkout diproses, Then sistem
                //  menggunakan harga terkini dari server dan MENAMPILKAN
                //  NOTIFIKASI PERUBAHAN HARGA ke buyer sebelum melanjutkan."
                //
                // `price_seen` dikirim client sebagai harga yang SEDIKAT buyer
                // lihat di keranjang. Nilainya HANYA dipakai untuk
                // MENGABARI — TIDAK PERNAH dipakai menghitung total. Total
                // selalu dari `skus.price` di atas.
                //
                // Tanpa pemeriksaan ini, backend tetap aman (harga server
                // selalu dipakai) tapi buyer akan diam-diam dikenai harga
                // baru yang lebih mahal.
                // ------------------------------------------------------------------
                $priceSeen = $item['price_seen'] ?? null;

                if ($priceSeen !== null && (int) $priceSeen !== $unitPrice) {
                    $priceChanges[] = [
                        'sku_id' => $sku->getKey(),
                        'sku_code' => $sku->code,
                        'product_name' => $sku->product?->name ?? '[Nama Produk]',
                        'old_price' => (int) $priceSeen,
                        'new_price' => $unitPrice,
                        'quantity' => $quantity,
                    ];
                }

                $items[] = [
                    'sku_id' => $sku->getKey(),
                    'product_name' => $sku->product?->name ?? '[Nama Produk]',
                    'product_slug' => $sku->product?->slug ?? '',
                    'variant_name' => $sku->variant?->name,
                    'sku_code' => $sku->code,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $unitPrice * $quantity,
                    'attributes' => $this->extractAttributes($sku),
                    'image_path' => $sku->product?->media()->first()?->path,
                ];
            }

            $subtotal = array_sum(array_column($items, 'subtotal'));
            $parcels = ShippingQuoteService::buildParcels($parcelsInput);

            // 3. Voucher — divalidasi dan farekonsumsikan secara atomik.
            $voucher = null;
            $discount = 0;
            $voucherCode = $data['voucher_code'] ?? null;

            if ($voucherCode !== null && trim($voucherCode) !== '') {
                $result = $this->applyVoucher->execute($voucherCode, $subtotal);
                $voucher = $result['voucher'];
                $discount = $result['discount'];
            }

            // 4. Ongkir — dari client TIDAK DIPERCAYA. Dihitung ulang dari
            //    cache quote ongkir yang sudah diverifikasi server (lihat
            //    ShippingQuoteService). Nilai client hanya untuk perbandingan.
            $shippingCost = $this->pricing->resolveShippingCost(
                $data['shipping_courier'] ?? null,
                $data['shipping_service'] ?? null,
                $data['shipping_cost'] ?? 0,
                $data['shipping_address']['city_code'] ?? null,
                $parcels,
            );

            $grandTotal = max(0, $subtotal - $discount) + $shippingCost;

            // ------------------------------------------------------------------
            // PRD §3.6 AC: beri tahu buyer sebelum melanjutkan.
            //
            // Dilempar DI DALAM transaksi, jadi semua work di atas otomatis
            // rollback — tidak ada order setengah jadi.
            //
            // `acknowledge_price_change` dari client boleh membuat checkout
            // diteruskan: buyer sudah melihat tabel perubahan dan menyetujuinya.
            // Tanpa itu, buyer akan terjebak di halaman konfirmasi terus
            //
            // karena harga akan "berubah" lagi pada percobaan berikutnya.
            // ------------------------------------------------------------------
            if ($priceChanges !== [] && ! ($data['acknowledge_price_change'] ?? false)) {
                throw PriceChangedException::make($priceChanges, $grandTotal);
            }

            // 5. Tentukan masa bayar sementara. NANTI ditimpa dengan nilai
            //    efektif dari gateway di FASE 2 (PRD §3.6).
            $provisionalExpiry = now()->addMinutes((int) config('palorinjani.reservation.provisional_minutes', 30));

            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => $user->getKey(),
                'status' => OrderStatus::MenungguPembayaran,
                'needs_reconciliation' => false,
                'shipping_address' => $addressSnapshot,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'shipping_cost' => $shippingCost,
                'service_fee' => 0,
                'grand_total' => $grandTotal,
                'shipping_courier' => $data['shipping_courier'] ?? null,
                'shipping_service' => $data['shipping_service'] ?? null,
                'shipping_etd' => $data['shipping_etd'] ?? null,
                'voucher_id' => $voucher?->getKey(),
                'voucher_code' => $voucher?->code,
                'payment_expires_at' => $provisionalExpiry,
            ]);

            // 6. Snapshot item.
            foreach ($items as $item) {
                $order->items()->create($item);
            }

            // 7. Kunci stok. Melempar InsufficientStockException => rollback
            //    semua, termasuk order yang baru dibuat.
            $this->reserveStock->execute(
                array_map(fn ($i) => ['sku_id' => $i['sku_id'], 'quantity' => $i['quantity']], $items),
                $order,
                $provisionalExpiry,
            );

            // 8. Konsumsi kuota voucher (SETELAH validasi stok lolos, supaya
            //    kuota tidak terpakai untuk order yang gagal).
            if ($voucher !== null) {
                $this->applyVoucher->commitRedemption($voucher, $order, $discount);
            }

            // 9. Audit trail awal.
            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::MenungguPembayaran->value,
                'actor_id' => $user->getKey(),
                'actor_type' => OrderActor::Customer->value,
                'note' => 'Order dibuat oleh pelanggan.',
            ]);

            return $order;
        });

        // ------------------------------------------------------------------
        // FASE 2 — panggil payment gateway (di luar transaksi)
        // ------------------------------------------------------------------
        try {
            $charge = $this->gateway->createTransaction(
                orderNumber: $order->order_number,
                amount: $order->grand_total,
                method: $data['payment_method'],
            );

            // PENTING (PRD §3.6): masa reservasi mengikuti batas bayar EFEKTIF
            // dari gateway, bukan angka tetap. Ini yang membuat instruksi
            // "jangan pakai 15 menit kalau gateway 24 jam" terpenuhi.
            $effectiveExpiry = $charge->expiresAt
                ? Carbon::instance($charge->expiresAt)
                : now()->addMinutes((int) config('palorinjani.reservation.provisional_minutes', 30));

            $order->payments()->create([
                'gateway_transaction_id' => $charge->transactionId,
                'gateway' => config('palorinjani.gateway.name'),
                'method' => $data['payment_method'],
                'amount' => $order->grand_total,
                'status' => 'pending',
                'instructions' => $charge->instructions,
                'expires_at' => $effectiveExpiry,
            ]);

            $order->forceFill(['payment_expires_at' => $effectiveExpiry])->save();
            $this->syncReservationExpiry($order, $effectiveExpiry);

            return $order->fresh('payments');
        } catch (GatewayException $e) {
            // Pembuatan pembayaran gagal. Order TIDAK boleh menggantung di
            // status "menunggu pembayaran" dengan stok terkunci selamanya.
            $this->handleGatewayFailure($order);

            throw $e;
        }
    }

    /**
     * Pembuatan gateway gagal -> batalkan order & lepas stok.
     *
     * WAJIB aman dijalankan ulang: client bisa saja menekan "Bayar" lagi, dan
     * retry dari sisi client akan memanggil CreateOrder lagi (order baru),
     * tapi jika prosesnya di-retry di level HTTP, order yang sama bisa
     * diproses dua kali. Idempotensi di ReleaseReservation yang menjaganya.
     */
    private function handleGatewayFailure(Order $order): void
    {
        app(TransitionOrderStatus::class)->execute(
            $order,
            OrderStatus::Dibatalkan,
            OrderActor::System,
            note: 'Dibatalkan otomatis: pembuatan transaksi pembayaran gagal di gateway.',
        );
    }

    /**
     * Selaraskan masa reservasi dengan batas bayar gateway.
     *
     * Dipanggil SETELAH respons gateway diterima. Tanpa ini, reservasi bisa
     * kedaluwarsa sebelum instruksi pembayaran masih aktif — persis skenario
     * yang PRD §3.6 minta untuk dihindari.
     */
    private function syncReservationExpiry(Order $order, \DateTimeInterface $expiresAt): void
    {
        StockReservation::where('order_id', $order->getKey())
            ->where('status', ReservationStatus::Active->value)
            ->update(['expires_at' => $expiresAt, 'updated_at' => now()]);
    }

    /**
     * Kumpulkan nilai atribut varian untuk snapshot.
     */
    private function extractAttributes(Sku $sku): array
    {
        if ($sku->variant === null) {
            return [];
        }

        $values = $sku->variant->attributeValues()->with('attribute')->get();
        $result = [];

        foreach ($values as $value) {
            $result[$value->attribute?->name ?? 'Atribut'] = $value->value;
        }

        return $result;
    }

    /**
     * Salin alamat pengiriman ke bentuk snapshot immutable.
     *
     * PRD §5: "Snapshot alamat lengkap dan ongkir" di order.
     */
    private function buildAddressSnapshot(array $address): array
    {
        return [
            'recipient_name' => $address['recipient_name'] ?? '',
            'phone' => $address['phone'] ?? '',
            'province' => $address['province'] ?? '',
            'province_code' => $address['province_code'] ?? null,
            'city' => $address['city'] ?? '',
            'city_code' => $address['city_code'] ?? null,
            'district' => $address['district'] ?? null,
            'subdistrict' => $address['subdistrict'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'street' => $address['street'] ?? '',
            'notes' => $address['notes'] ?? null,
        ];
    }
}
