<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrderManualVerificationSeeder extends Seeder
{
    /**
     * Seed orders specifically structured for manual verification in Filament Admin.
     */
    public function run(): void
    {
        // 1. Ensure Admin users exist for login
        $envAdmin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@palorinjani.local')],
            [
                'name' => env('ADMIN_NAME', 'Admin Palo Senaru'),
                'password_hash' => Hash::make(env('ADMIN_PASSWORD', 'Senaru#2026Aman')),
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@palorinjani.com'],
            [
                'name' => 'Admin Palo Rinjani',
                'password_hash' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // 2. Ensure Customer user exists
        $customer = User::firstOrCreate(
            ['email' => 'customer@palorinjani.com'],
            [
                'name' => 'Rinjani Explorer',
                'phone' => '081234567890',
                'password_hash' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // 3. Customer default shipping address
        $address = Address::firstOrCreate(
            [
                'user_id' => $customer->id,
                'label' => 'Basecamp Senaru',
            ],
            [
                'recipient_name' => 'Rinjani Explorer',
                'phone' => '081234567890',
                'province' => 'Nusa Tenggara Barat',
                'city' => 'Lombok Utara',
                'district' => 'Bayan',
                'postal_code' => '83354',
                'full_address' => 'Jl. Pariwisata Senaru No. 45, RT 02 / RW 01, Bayan, Lombok Utara, NTB 83354',
                'is_default' => true,
            ]
        );

        // 4. Retrieve SKUs
        $skuM = Sku::with(['product', 'variant'])->where('sku_code', 'PALORI-MAR-M')->first()
            ?? Sku::with(['product', 'variant'])->first();

        $skuL = Sku::with(['product', 'variant'])->where('sku_code', 'PALORI-HIT-L')->first()
            ?? Sku::with(['product', 'variant'])->skip(1)->first()
            ?? $skuM;

        $skuCancel = Sku::with(['product', 'variant'])->where('sku_code', 'PALORI-BIR-M')->first()
            ?? $skuM;

        // Reset cancellation test SKU stock to exactly 100 for clear verification
        if ($skuCancel) {
            $skuCancel->update(['stock' => 100]);
        }

        // 5. Clean up any previous test orders for repeatability
        Order::where('order_number', 'like', 'ORD-TEST-%')->delete();

        // ---------------------------------------------------------------------
        // Skenario 1: Untuk Uji Step 2 — Klik "Proses" (Dibayar -> Diproses)
        // ---------------------------------------------------------------------
        $order1 = Order::create([
            'order_number' => 'ORD-TEST-001-DIBAYAR',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 270000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 25000.00,
            'total' => 295000.00,
            'shipping_recipient_name' => 'Rinjani Explorer',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => $address->full_address,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG (Reguler)',
            'tracking_number' => null,
            'created_at' => now()->subHours(2),
        ]);

        OrderItem::create([
            'order_id' => $order1->id,
            'sku_id' => $skuM?->id,
            'product_name_snapshot' => $skuM?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuM?->variant?->name ?? 'Maroon / Size M',
            'sku_code_snapshot' => $skuM?->sku_code ?? 'PALORI-MAR-M',
            'price_snapshot' => 270000.00,
            'quantity' => 1,
            'subtotal' => 270000.00,
        ]);

        Payment::create([
            'order_id' => $order1->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-001-PAY',
            'payment_method' => 'bank_transfer_bca',
            'amount' => 295000.00,
            'status' => 'settlement',
            'paid_at' => now()->subHours(2),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order1->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subHours(3),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order1->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran Rp 295.000 lunas via Midtrans SNAP.',
            'created_at' => now()->subHours(2),
        ]);

        // ---------------------------------------------------------------------
        // Skenario 2: Untuk Uji Step 3 — Klik "Kirim" & Input Resi (Diproses -> Dikirim)
        // ---------------------------------------------------------------------
        $order2 = Order::create([
            'order_number' => 'ORD-TEST-002-DIPROSES',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PROCESSING,
            'subtotal' => 350000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 30000.00,
            'total' => 380000.00,
            'shipping_recipient_name' => 'Rinjani Explorer',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => $address->full_address,
            'shipping_courier' => 'J&T Express',
            'shipping_service' => 'EZ (Reguler)',
            'tracking_number' => null,
            'created_at' => now()->subHours(5),
        ]);

        OrderItem::create([
            'order_id' => $order2->id,
            'sku_id' => $skuL?->id,
            'product_name_snapshot' => $skuL?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuL?->variant?->name ?? 'Hitam / Size L',
            'sku_code_snapshot' => $skuL?->sku_code ?? 'PALORI-HIT-L',
            'price_snapshot' => 350000.00,
            'quantity' => 1,
            'subtotal' => 350000.00,
        ]);

        Payment::create([
            'order_id' => $order2->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-002-PAY',
            'payment_method' => 'gopay',
            'amount' => 380000.00,
            'status' => 'settlement',
            'paid_at' => now()->subHours(5),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order2->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subHours(6),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order2->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran terkonfirmasi lunas.',
            'created_at' => now()->subHours(5),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order2->id,
            'changed_by_admin_id' => $envAdmin->id,
            'from_status' => Order::STATUS_PAID,
            'to_status' => Order::STATUS_PROCESSING,
            'note' => 'Pesanan sedang disiapkan dan dikemas oleh staf gudang.',
            'created_at' => now()->subHours(4),
        ]);

        // ---------------------------------------------------------------------
        // Skenario 3: Untuk Uji Step 4 — "Koreksi Resi" pada Detail Dikirim
        // ---------------------------------------------------------------------
        $order3 = Order::create([
            'order_number' => 'ORD-TEST-003-DIKIRIM',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_SHIPPED,
            'subtotal' => 350000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 40000.00,
            'total' => 390000.00,
            'shipping_recipient_name' => 'Rinjani Explorer',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => $address->full_address,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'YES (Yakin Esok Sampai)',
            'tracking_number' => 'JNE-TEST-998811',
            'created_at' => now()->subDay(),
        ]);

        OrderItem::create([
            'order_id' => $order3->id,
            'sku_id' => $skuL?->id,
            'product_name_snapshot' => $skuL?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuL?->variant?->name ?? 'Hitam / Size L',
            'sku_code_snapshot' => $skuL?->sku_code ?? 'PALORI-HIT-L',
            'price_snapshot' => 350000.00,
            'quantity' => 1,
            'subtotal' => 350000.00,
        ]);

        Payment::create([
            'order_id' => $order3->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-003-PAY',
            'payment_method' => 'qris',
            'amount' => 390000.00,
            'status' => 'settlement',
            'paid_at' => now()->subDay(),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order3->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subDay()->subHours(3),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order3->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran terkonfirmasi lunas.',
            'created_at' => now()->subDay()->subHours(2),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order3->id,
            'changed_by_admin_id' => $envAdmin->id,
            'from_status' => Order::STATUS_PAID,
            'to_status' => Order::STATUS_PROCESSING,
            'note' => 'Pesanan disiapkan oleh gudang.',
            'created_at' => now()->subDay()->subHour(),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order3->id,
            'changed_by_admin_id' => $envAdmin->id,
            'from_status' => Order::STATUS_PROCESSING,
            'to_status' => Order::STATUS_SHIPPED,
            'note' => 'Pesanan diserahkan ke kurir JNE dengan nomor resi JNE-TEST-998811.',
            'created_at' => now()->subDay(),
        ]);

        // ---------------------------------------------------------------------
        // Skenario 4: Untuk Uji Step 5 — Batalkan Pesanan Diproses & Pulihkan Stok (+2)
        // ---------------------------------------------------------------------
        $order4 = Order::create([
            'order_number' => 'ORD-TEST-004-BATALKAN',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PROCESSING,
            'subtotal' => 540000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 25000.00,
            'total' => 565000.00,
            'shipping_recipient_name' => 'Rinjani Explorer',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => $address->full_address,
            'shipping_courier' => 'SiCepat',
            'shipping_service' => 'REG (Reguler)',
            'tracking_number' => null,
            'created_at' => now()->subHours(3),
        ]);

        OrderItem::create([
            'order_id' => $order4->id,
            'sku_id' => $skuCancel?->id,
            'product_name_snapshot' => $skuCancel?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuCancel?->variant?->name ?? 'Biru / Size M',
            'sku_code_snapshot' => $skuCancel?->sku_code ?? 'PALORI-BIR-M',
            'price_snapshot' => 270000.00,
            'quantity' => 2,
            'subtotal' => 540000.00,
        ]);

        Payment::create([
            'order_id' => $order4->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-004-PAY',
            'payment_method' => 'bank_transfer_mandiri',
            'amount' => 565000.00,
            'status' => 'settlement',
            'paid_at' => now()->subHours(3),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order4->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subHours(4),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order4->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran terkonfirmasi lunas.',
            'created_at' => now()->subHours(3),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order4->id,
            'changed_by_admin_id' => $envAdmin->id,
            'from_status' => Order::STATUS_PAID,
            'to_status' => Order::STATUS_PROCESSING,
            'note' => 'Pesanan disiapkan oleh staf gudang.',
            'created_at' => now()->subHours(2),
        ]);

        // ---------------------------------------------------------------------
        // Skenario 5 & 6: Untuk Uji Step 6 — Aksi Massal "Proses Massal" & "Ekspor CSV"
        // ---------------------------------------------------------------------
        $order5 = Order::create([
            'order_number' => 'ORD-TEST-005-MASSAL-A',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 270000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 35000.00,
            'total' => 305000.00,
            'shipping_recipient_name' => 'Ahmad Fauzi',
            'shipping_phone' => '081987654321',
            'shipping_full_address' => 'Jl. Raya Sembalun No. 12, Sembalun Bumbung, Lombok Timur, NTB 83656',
            'shipping_courier' => 'SiCepat',
            'shipping_service' => 'BEST (Besok Sampai)',
            'tracking_number' => null,
            'created_at' => now()->subMinutes(30),
        ]);

        OrderItem::create([
            'order_id' => $order5->id,
            'sku_id' => $skuM?->id,
            'product_name_snapshot' => $skuM?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuM?->variant?->name ?? 'Maroon / Size M',
            'sku_code_snapshot' => $skuM?->sku_code ?? 'PALORI-MAR-M',
            'price_snapshot' => 270000.00,
            'quantity' => 1,
            'subtotal' => 270000.00,
        ]);

        Payment::create([
            'order_id' => $order5->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-005-PAY',
            'payment_method' => 'bank_transfer_bni',
            'amount' => 305000.00,
            'status' => 'settlement',
            'paid_at' => now()->subMinutes(30),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order5->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subMinutes(40),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order5->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran terkonfirmasi lunas.',
            'created_at' => now()->subMinutes(30),
        ]);

        $order6 = Order::create([
            'order_number' => 'ORD-TEST-006-MASSAL-B',
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 350000.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 20000.00,
            'total' => 370000.00,
            'shipping_recipient_name' => 'Siti Nurhaliza',
            'shipping_phone' => '087811223344',
            'shipping_full_address' => 'Jl. Langko No. 88, Dasan Agung, Kota Mataram, NTB 83125',
            'shipping_courier' => 'J&T Express',
            'shipping_service' => 'EZ (Reguler)',
            'tracking_number' => null,
            'created_at' => now()->subMinutes(15),
        ]);

        OrderItem::create([
            'order_id' => $order6->id,
            'sku_id' => $skuL?->id,
            'product_name_snapshot' => $skuL?->product?->name ?? 'Palo Rinjani Classic Hoodie',
            'variant_name_snapshot' => $skuL?->variant?->name ?? 'Hitam / Size L',
            'sku_code_snapshot' => $skuL?->sku_code ?? 'PALORI-HIT-L',
            'price_snapshot' => 350000.00,
            'quantity' => 1,
            'subtotal' => 350000.00,
        ]);

        Payment::create([
            'order_id' => $order6->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TEST-006-PAY',
            'payment_method' => 'qris',
            'amount' => 370000.00,
            'status' => 'settlement',
            'paid_at' => now()->subMinutes(15),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order6->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat oleh pelanggan.',
            'created_at' => now()->subMinutes(25),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order6->id,
            'changed_by_admin_id' => null,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran terkonfirmasi lunas.',
            'created_at' => now()->subMinutes(15),
        ]);
    }
}
