<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResetStoreCatalog extends Command
{
    /**
     * Nama dan signature command console.
     */
    protected $signature = 'store:reset-catalog {--force : Jalankan pembersihan langsung tanpa konfirmasi interaktif} {--reset-admins : Hapus akun admin tambahan dan sisakan hanya akun superadmin}';

    /**
     * Deskripsi command console.
     */
    protected $description = 'Bersihkan data katalog produk, pesanan, inventaris stok, koleksi, voucher, dan akun pembeli; sisakan kategori Apparel.';

    /**
     * Eksekusi command console.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('PERINGATAN: Perintah ini akan menghapus semua produk, pesanan, stok, koleksi, voucher, dan akun pembeli di database. Lanjutkan?', false)) {
            $this->warn('Operasi dibatalkan oleh pengguna.');
            return self::SUCCESS;
        }

        $this->info('Memulai pembersihan data katalog, transaksi, stok, dan akun pembeli...');

        $deletedCounts = [];

        try {
            DB::transaction(function () use (&$deletedCounts) {
                // 1. Pesanan & Pembayaran (Order & Transactions)
                $deletedCounts['payment_webhook_logs'] = DB::table('payment_webhook_logs')->delete();
                $deletedCounts['payments'] = DB::table('payments')->delete();
                $deletedCounts['guest_checkout_logs'] = DB::table('guest_checkout_logs')->delete();
                $deletedCounts['order_status_histories'] = DB::table('order_status_histories')->delete();
                $deletedCounts['order_items'] = DB::table('order_items')->delete();
                $deletedCounts['orders'] = DB::table('orders')->delete();

                // 2. Keranjang Belanja (Carts)
                $deletedCounts['cart_items'] = DB::table('cart_items')->delete();
                $deletedCounts['carts'] = DB::table('carts')->delete();

                // 3. Akun Pembeli, Alamat & Token Pelanggan (Customers / Buyers)
                if (Schema::hasTable('personal_access_tokens')) {
                    $deletedCounts['personal_access_tokens (buyer)'] = DB::table('personal_access_tokens')
                        ->where('tokenable_type', 'App\Models\User')
                        ->delete();
                }
                if (Schema::hasTable('notifications')) {
                    $deletedCounts['notifications (buyer)'] = DB::table('notifications')
                        ->where('notifiable_type', 'App\Models\User')
                        ->delete();
                }
                if (Schema::hasTable('password_reset_tokens')) {
                    $deletedCounts['password_reset_tokens'] = DB::table('password_reset_tokens')->delete();
                }
                $deletedCounts['addresses'] = DB::table('addresses')->delete();
                $deletedCounts['users'] = DB::table('users')->delete();

                // 4. Stok, Opname & Riwayat Mutasi (Inventory)
                $deletedCounts['stock_movements'] = DB::table('stock_movements')->delete();
                $deletedCounts['stock_opname_items'] = DB::table('stock_opname_items')->delete();
                $deletedCounts['stock_opnames'] = DB::table('stock_opnames')->delete();

                // 5. Koleksi (Collections)
                $deletedCounts['collection_product'] = DB::table('collection_product')->delete();
                $deletedCounts['collections'] = DB::table('collections')->delete();

                // 6. Produk, Varian, SKU & Media (Catalog)
                $deletedCounts['product_media'] = DB::table('product_media')->delete();
                $deletedCounts['skus'] = DB::table('skus')->delete();
                $deletedCounts['product_variants'] = DB::table('product_variants')->delete();
                $deletedCounts['products'] = DB::table('products')->delete();

                // 7. Voucher Promosi
                $deletedCounts['vouchers'] = DB::table('vouchers')->delete();

                // 8. Audit Log Admin terkait entitas yang dibersihkan
                if (Schema::hasTable('admin_activity_logs')) {
                    $deletedCounts['admin_activity_logs (katalog/pesanan)'] = DB::table('admin_activity_logs')
                        ->whereIn('model_type', [
                            'App\Models\Sku',
                            'App\Models\Order',
                            'App\Models\Product',
                            'App\Models\Category',
                            'App\Models\Collection',
                        ])->delete();
                }

                // 9. Kategori: Sisakan 'apparel', bersihkan kategori lainnya
                $apparel = Category::firstOrCreate(
                    ['slug' => 'apparel'],
                    ['name' => 'Apparel']
                );

                // Lepas seluruh relasi parent_id pada semua kategori untuk menjamin tidak ada FK violation
                DB::table('categories')->update(['parent_id' => null]);

                $deletedCounts['categories (selain apparel)'] = DB::table('categories')
                    ->where('id', '!=', $apparel->id)
                    ->delete();

                // 10. Reset Akun Admin Dummy (Opsional jika --reset-admins dispesifikasikan)
                if ($this->option('reset-admins')) {
                    $superAdminEmail = env('ADMIN_EMAIL', 'admin@palorinjani.local');
                    $superAdmin = DB::table('admin_users')->where('email', $superAdminEmail)->first();

                    if ($superAdmin) {
                        $deletedCounts['admin_users (dummy)'] = DB::table('admin_users')
                            ->where('id', '!=', $superAdmin->id)
                            ->delete();

                        $deletedCounts['roles (dummy)'] = DB::table('roles')
                            ->when($superAdmin->role_id, fn ($q) => $q->where('id', '!=', $superAdmin->role_id))
                            ->where('name', '!=', 'superadmin')
                            ->delete();
                    }
                }
            });

            // Invalidate cache kategori & order status counts
            Cache::forget('catalog:categories');
            Cache::forget('orders:status-counts');

            $this->newLine();
            $this->info('Pembersihan database berhasil diselesaikan.');
            $this->newLine();

            $rows = [];
            foreach ($deletedCounts as $table => $count) {
                $rows[] = [$table, $count];
            }

            $this->table(['Tabel / Entitas', 'Jumlah Baris Dihapus'], $rows);

            // Verifikasi kategori apparel
            $apparel = Category::where('slug', 'apparel')->first();
            $this->line("Kategori dipertahankan: <info>{$apparel->name}</info> (slug: <comment>{$apparel->slug}</comment>, id: {$apparel->id})");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Gagal melakukan pembersihan: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
