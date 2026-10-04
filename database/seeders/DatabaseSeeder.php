<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Shared\Models\AppSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder data awal.
 *
 * ===================================================================
 *  ATURAN PALING PENTING DI SEEDER INI
 * ===================================================================
 * PRD §1.1A: "Daftar SKU, jenis barang spesifik (misalnya kaos, topi, tas),
 * harga, varian, stok, dan bahan BELUM dapat dipastikan dari sumber publik
 * yang terakses. Jangan mengisi seed katalog atau klaim jenis produk tertentu
 * tanpa katalog dari pemilik."
 *
 * PRD §6A kriteria penerimaan: "Tidak ada produk contoh yang dipublikasikan
 * sebelum admin mengisi data asli."
 *
 * Maka seeder ini TIDAK membuat produk, varian, SKU, maupun harga APA PUN.
 * Database hasil `php artisan migrate --seed` akan memiliki katalog kosong,
 * dan itu adalah perilaku yang BENAR, bukan bug.
 *
 * Admin mengisi katalog lewat panel Filament memakai data asli dari pemilik
 * brand. Lihat docs/ARSITEKTUR.md §4.
 *
 * Yang boleh di-seed:
 *   - Akun admin pertama (dari .env, bukan hardcode).
 *   - app_settings default (dengan nilai placeholder yang jujur).
 *   - Atribut generik (Ukuran, Warna) — ini struktur, bukan data produk.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Menyiapkan data awal PALORINJANI...');

        $this->seedAdminUser();
        $this->seedAppSettings();
        $this->seedGenericAttributes();

        // Pengaman: kalau pengaman ini dimatikan dan tetap mau membuat
        // produk contoh, itu hanya boleh di luar produksi.
        if (config('palorinjani.allow_seed_products')) {
            $this->command?->warn(
                'ALLOW_SEED_PRODUCTS=true. Katalog contoh akan dibuat. '.
                'JANGAN pernah mengaktifkan ini di produksi.',
            );
        } else {
            $this->command?->info(
                'Katalog sengaja dibiarkan kosong (PRD §1.1A & §6A). '.
                'Isi lewat panel admin memakai katalog asli pemilik brand.',
            );
        }
    }

    /**
     * Akun admin pertama.
     *
     * Password & email diambil dari .env, TIDAK di-hardcode di sini. Kalau
     * nilainya kosong, admin TIDAK dibuat sama sekali — lebih baik tidak ada
     * admin daripada ada admin dengan password yang sudah dipublikasikan di
     * repository.
     */
    private function seedAdminUser(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'ADMIN_EMAIL / ADMIN_PASSWORD belum diisi di .env. '.
                'Akun admin TIDAK dibuat. Buat manual lewat: php artisan make:filament-user',
            );

            return;
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Admin Palo Senaru'),
                // Cast `hashed` di model User akan mem-bcrypt otomatis.
                'password' => $password,
                'role' => 'superadmin',
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Akun superadmin dibuat: {$email}");
    }

    /**
     * Pengaturan aplikasi dengan nilai placeholder yang jujur.
     *
     * Perhatikan: nilai yang belum disetujui pemilik diisi dengan teks
     * placeholder, bukan angka/tebakan. Contohnya jam operasional TIDAK diisi
     * "07.00-21.00" karena kebijakan itu belum dikonfirmasi (OD-05).
     */
    private function seedAppSettings(): void
    {
        $placeholders = config('palorinjani.placeholders');

        $defaults = [
            [
                'key' => 'store.name',
                'value' => config('palorinjani.brand.public_name'),
                'group' => 'store',
                'label' => 'Nama Toko',
            ],
            [
                'key' => 'store.address',
                // PENTING: PRD §6A meminta konfirmasi pemilik untuk alamat
                // final. Jangan isi alamat karangan.
                'value' => '[Alamat Toko dari Admin]',
                'group' => 'store',
                'label' => 'Alamat Toko',
            ],
            [
                'key' => 'store.city',
                'value' => config('palorinjani.shipping.origin_city'),
                'group' => 'store',
                'label' => 'Kota Toko',
            ],
            [
                'key' => 'store.phone',
                'value' => null,
                'group' => 'store',
                'label' => 'Telepon Toko',
            ],
            [
                'key' => 'store.opening_hours',
                // null, bukan jam tebakan. OD-05 masih DITUNDA.
                'value' => null,
                'group' => 'store',
                'label' => 'Jam Operasional',
                'description' => 'Dikosongkan sampai pemilik mengonfirmasi kebijakan jam buka (OD-05).',
            ],
            [
                'key' => 'store.pickup_available',
                'value' => false,
                'group' => 'store',
                'label' => 'Pickup di Toko',
                'description' => 'Sistem_map §4.1.4: pickup tidak diaktifkan sebelum kebijakan pemilik dikonfirmasi (OD-05).',
            ],
            [
                'key' => 'whatsapp.cs_number',
                'value' => null,
                'group' => 'whatsapp',
                'label' => 'Nomor WhatsApp CS',
                'description' => 'PRD §3.11: nomor taken dari sini, bukan hardcode, supaya bisa diganti tanpa deploy.',
            ],
            [
                'key' => 'whatsapp.cs_message_template',
                'value' => null,
                'group' => 'whatsapp',
                'label' => 'Template Pesan CS',
            ],
            [
                'key' => 'brand.tagline',
                'value' => '[Tagline disetujui brand]',
                'group' => 'general',
                'label' => 'Tagline',
            ],
            [
                'key' => 'brand.story',
                'value' => null,
                'group' => 'general',
                'label' => 'Kisah Brand',
            ],
            [
                'key' => 'brand.instagram_url',
                'value' => null,
                'group' => 'general',
                'label' => 'URL Instagram',
            ],
            [
                'key' => 'brand.instagram_handle',
                'value' => null,
                'group' => 'general',
                'label' => 'Handle Instagram',
            ],
            // ================================================================
            // KANAL MEDIA SOSIAL
            // ================================================================
            // Facebook & TikTok ditambahkan karena footer butuh tautan ke
            // keempat kanal, dan PRD §3.11 menetapkan semua kanal resmi
            // diambil dari pengaturan admin — bukan ditulis di kode.
            //
            // Semuanya `null` sampai pemilik mengisi. Footer sengaja
            // MENYEMBUNYIKAN ikon yang belum diisi, bukan menampilkan ikon
            // mati: ikon sosial media yang menuju ke `null`=http://localhost
            // bikin pengunjung mengira brand-nya tidak punya akun itu.
            [
                'key' => 'brand.facebook_url',
                'value' => null,
                'group' => 'general',
                'label' => 'URL Facebook',
                'description' => 'Halaman resmi. Kosongkan kalau brand tidak punya akun Facebook.',
            ],
            [
                'key' => 'brand.tiktok_url',
                'value' => null,
                'group' => 'general',
                'label' => 'URL TikTok',
                'description' => 'Halaman resmi. Kosongkan kalau brand tidak punya akun TikTok.',
            ],
        ];

        foreach ($defaults as $setting) {
            AppSetting::firstOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => ['value' => $setting['value']],
                    'group' => $setting['group'],
                    'label' => $setting['label'],
                    'description' => $setting['description'] ?? null,
                ],
            );
        }

        $this->command?->info(count($defaults).' pengaturan aplikasi dibuat.');
    }

    /**
     * Atribut generik: Ukuran dan Warna.
     *
     * INI BOLEH karena bukan data produk — hanya STRUKTUR filter. PRD §3.3
     * menyebut ukuran/warna sebagai filter, dan atributnya bisa
     * dipakai produk apa pun yang punya nilai.
     *
     * `is_filterable = true` karena keduanya memang jadi filter di katalog.
     */
    private function seedGenericAttributes(): void
    {
        $attributes = [
            ['name' => 'Ukuran', 'slug' => 'ukuran', 'is_filterable' => true, 'sort_order' => 1],
            ['name' => 'Warna', 'slug' => 'warna', 'is_filterable' => true, 'sort_order' => 2],
            ['name' => 'Bahan', 'slug' => 'bahan', 'is_filterable' => false, 'sort_order' => 3],
        ];

        foreach ($attributes as $attribute) {
            Attribute::firstOrCreate(
                ['slug' => $attribute['slug']],
                $attribute,
            );
        }

        $this->command?->info(count($attributes).' atribut generik dibuat.');
    }
}
