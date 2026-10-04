<?php

namespace Tests;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Sku;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Helper pembuatan data untuk test.
 *
 * ===================================================================
 *  MENGAPA TIDAK ADA `ProductFactory` DENGAN NAMA & HARGA NYATA
 * ===================================================================
 * PRD §1.1A melarang mengisi katalog dengan produk asumsi. Kalau factory
 * punya default "Kaos Polos" + "Rp 150.000", cepat atau lambat data itu akan
 * bocor ke seeder, ke assertion test, lalu ke screenshot dokumentasi — dan
 * kita kembali ke posisi mempublikasikan produk fiktif.
 *
 * Karena itu test WAJIB menyebut nilai yang dipakainya secara eksplisit.
 * Itu membuat test jelas, dan tidak menyamar jadi data produksi.
 */
trait CreatesTestData
{
    /**
     * Buat user pelanggan biasa (tanpa role).
     *
     * `role: null` berarti pelanggan. Akun admin selalu punya role
     * `superadmin` atau `staff`.
     */
    protected function makeCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Pembeli Uji',
            'email' => 'pembeli-'.uniqid().'@uji.local',
            'password' => Hash::make('rahasia-uji-123'),
            'role' => null,
        ], $attributes));
    }

    /**
     * Buat admin panel.
     */
    protected function makeAdmin(string $role = 'superadmin'): User
    {
        return User::create([
            'name' => 'Admin Uji',
            'email' => 'admin-'.uniqid().'@uji.local',
            'password' => Hash::make('Senaru#2026Aman'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Header Authorization untuk sebuah user.
     *
     * Perhatikan: method ini HANYA membuat token. Untuk memakai token
     * berbeda pada request berikutnya dalam satu test, panggil
     * `lupakanGuard()` lebih dulu — lihat docblock-nya.
     *
     * @return array<string, string>
     */
    protected function tokenFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('uji')->plainTextToken,
        ];
    }

    /**
     * ===================================================================
     *  BERSIHKAN CACHE AUTH SEBELUM PINDAH USER
     * ===================================================================
     * Instance `AuthManager` beserta objek guard-nya bertahan selama satu
     * method test. Setelah request pertama mengautentikasi user A, guard
     * masih "ingat" user A — sehingga request berikutnya yang membawa token
     * user B tetap dinilai sebagai user A.
     *
     * Ini BUKAN BUG APLIKASI. Di produksi setiap request adalah proses baru
     * dengan container baru, jadi hal ini tidak mungkin terjadi. `php artisan
     * serve` juga tidak terpengaruh, karena tiap request Dianekern oleh PHP's
     * built-in server.
     *
     * TETAPI di dalam test, memanggil cara yang salah akan menyebabkan dua
     * kegagalan yang sama-sama berbahaya:
     *
     *   - FALSE ALARM  -> test gagal padahal aplikasi benar. Waktu terbuang.
     *   - FALSE GREEN  -> test lolos padahal aplikasi salah. Bug terlewat.
     *
     * Yang kedua jauh lebih berbahaya. Karena itu: setiap kali satu test
     * memakai DUA user berbeda, panggil method ini tepat SEBELUM request
     * yang berganti user.
     */
    protected function lupakanGuard(): void
    {
        Auth::forgetGuards();
    }

    /**
     * Buat produk aktif beserta SKU-nya.
     *
     * Default memakai nama placeholder karena PRD §1.1A. Test yang butuh
     * nama lain harus menyatakannya.
     */
    protected function makeProduct(
        ?string $name = null,
        int $price = 100000,
        int $stock = 10,
        ?int $weightGrams = 500,
    ): Product {
        $name ??= config('palorinjani.placeholders.product_name');

        $category = Category::firstOrCreate(
            ['slug' => 'kategori-uji'],
            ['name' => 'Kategori Uji', 'is_active' => true],
        );

        $product = Product::create([
            'category_id' => $category->getKey(),
            'slug' => 'produk-'.uniqid(),
            'name' => $name,
            'status' => ProductStatus::Active,
            'published_at' => now()->subDay(),
        ]);

        $product->skus()->create([
            'code' => 'SKU-'.strtoupper(substr(md5($product->slug), 0, 8)),
            'price' => $price,
            'on_hand' => $stock,
            'weight_grams' => $weightGrams,
            'is_active' => true,
        ]);

        return $product->fresh();
    }

    /**
     * Ambil SKU pertama dari produk.
     */
    protected function firstSku(Product $product): Sku
    {
        return $product->skus()->firstOrFail();
    }
}
