# Palo Mountain Goods (PaloRinjani) — Backend

[![Laravel](https://img.shields.io/badge/Laravel-13%2B-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-v5-D97706?style=flat-square&logo=filament)](https://filamentphp.com)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15%2B-4169E1?style=flat-square&logo=postgresql)](https://www.postgresql.org)
[![TailwindCSS](https://img.shields.io/badge/TailwindCSS-v4-06B6D4?style=flat-square&logo=tailwindcss)](https://tailwindcss.com)
[![Tests](https://img.shields.io/badge/Tests-30%20Passed-22C55E?style=flat-square)](https://phpunit.de)

Backend dan panel administrasi untuk platform D2C (Direct-to-Consumer) e-commerce **Palo Mountain Goods**, brand perlengkapan luar ruang (*outdoor gear*), pakaian gunung, dan komoditas kopi khas lereng Gunung Rinjani.

Arsitektur aplikasi dibangun menggunakan pola **Modular Monolith** dengan Laravel 13 dan Filament Admin v5, mengutamakan performa tinggi, kesederhanaan operasional (KISS & YAGNI), dan integritas transaksional yang ketat.

---

## Daftar Isi
- [Arsitektur & Konsep](#arsitektur--konsep)
- [Fitur Utama yang Tersedia](#fitur-utama-yang-tersedia)
- [Public REST API v1](#public-rest-api-v1)
- [Spesifikasi Teknologi & Prasyarat](#spesifikasi-teknologi--prasyarat)
- [Panduan Instalasi & Setup Lokal](#panduan-instalasi--setup-lokal)
- [Kredensial Default Admin](#kredensial-default-admin)
- [Pengujian Otomatis & Standar Kode](#pengujian-otomatis--standar-kode)
- [Alur Branching Git & Multi-Agent Protocol](#alur-branching-git--multi-agent-protocol)
- [Struktur Direktori Backend](#struktur-direktori-backend)

---

## Arsitektur & Konsep

PaloRinjani mengadopsi prinsip **Modular Monolith** (SAM §1):
* **Single Deployable:** Seluruh modul backend berjalan dalam satu runtime PHP tanpa latensi HTTP internal.
* **Domain Ownership Boundaries:**
  * `CATALOG`: Manajemen kategori, produk, varian, galeri media, dan banner promosi.
  * `CART` & `ORDER`: Siklus transaksi pemesanan, reservasi stok, dan snapshot produk.
  * `VOUCHER`: Aturan kupon diskon dan batas kuota.
  * `ADMINSVC`: Panel admin Filament dengan integrasi role-based access control (RBAC).
  * `INTEGRATIONS`: Gerbang pembayaran (Midtrans/Xendit) dan kurir logistik (RajaOngkir).

---

## Fitur Utama yang Tersedia

### 1. Katalog & Manajemen Varian SKU (PRD §3.2)
* **Kategori Hirarki (Tree Structure):** Mendukung kategori induk (*parent*) dan sub-kategori tak terbatas beserta pembuatan `slug` otomatis untuk keramahan SEO.
* **Multi-Varian Terstruktur:** Pemisahan antara *Product*, *ProductVariant* (atribut kombinasi seperti warna/ukuran), dan *Sku*.
* **Manajemen SKU & Stok:** Setiap varian memiliki kode SKU unik, kontrol stok real-time (dengan proteksi database `stock >= 0`), dan opsi penyesuaian harga khusus (*price override*).
* **Atribut Fleksibel (`JSONB`):** Atribut produk disimpan secara terstruktur untuk mendukung tombol selector interaktif dan filter pencarian di sisi etalase (*storefront*).

### 2. Galeri Media Produk (PRD §3.2)
* **Multi-Upload Gambar:** Pengunggahan gambar produk langsung di dalam form utama dengan batas ukuran 2MB (format JPG, PNG, WEBP).
* **Drag-and-Drop Sort Order:** Pengaturan urutan tayang foto secara visual.
* **Thumbnail Utama Otomatis:** Foto pada urutan pertama otomatis dijadikan sebagai thumbnail produk di tabel admin dan katalog publik.

### 3. Manajemen Banner Promosi & Hero Carousel (PRD §3.10)
* **Jadwal Tayang Otomatis:** Banner memiliki pengaturan tanggal mulai (`start_date`) dan tanggal berakhir (`end_date`), serta label status cerdas (*Mendatang*, *Tayang*, *Kadaluarsa*, *Nonaktif*).
* **Visual Table Reordering:** Pengurutan tayang banner carousel secara instan di tabel admin.
* **Filter Scope Active:** Banner nonaktif atau di luar masa tayang otomatis disaring keluar dari etalase storefront.

### 4. Panel Admin Berbasis Filament v5 (PRD §3.10)
* **Tema Warna Brand Terintegrasi:** Mengadopsi token warna resmi *Palo Mountain Goods* (Primary *Palo Pine Green* `#1E5E3E`, Accent *Campfire Ochre* `#D96B27`, dan Neutral *Stone*).
* **Format Mata Uang Standar:** Tampilan tabel harga menggunakan format Rupiah resmi (`Rp xxx.xxx,xx`).
* **Indikator Stok Cerdas:** Badge status stok dengan warna dinamis (Merah: Habis, Kuning: Menipis $\le 5$, Hijau: Aman).

---

## Public REST API v1

REST API publik disediakan untuk dikonsumsi oleh etalase Storefront Next.js dengan prefix otomatis `/api/v1`:

| Method | Endpoint | Keterangan |
|---|---|---|
| `GET` | `/api/v1/banners` | Daftar banner carousel aktif, terjadwal, dan terurut. |
| `GET` | `/api/v1/categories` | Pohon hierarki kategori (*nested category tree*) beserta jumlah produk aktif. |
| `GET` | `/api/v1/products` | Katalog produk dengan filter (kategori, harga, featured, in-stock), pencarian, sorting, dan pagination (12 item/halaman). |
| `GET` | `/api/v1/products/{slug}` | Detail produk lengkap, eager loading varian, SKU, stok kalkulasi riil, dan galeri media terurut. |

### Format Standard Response
```json
{
  "success": true,
  "message": "Pesan deskriptif",
  "data": [...],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 12,
    "total": 60,
    "has_more": true
  }
}
```

---

## Spesifikasi Teknologi & Prasyarat

| Komponen | Versi Minimum / Spesifikasi |
|---|---|
| **PHP** | `^8.3` (diuji pada `PHP 8.5.10`, ekstensi: `pdo_pgsql`, `mbstring`, `openssl`, `fileinfo`, `gd`/`imagick`, `exif`) |
| **Composer** | `^2.2` |
| **Database** | PostgreSQL `15+` (atau SQLite untuk in-memory testing) |
| **Asset Bundler** | Node.js `20+` & NPM |
| **Framework** | Laravel `^13.17` (terpasang `13.33.0`) |
| **Admin Panel** | Filament `v5.8+` |
| **Image Processing** | `intervention/image:^4.0` (driver GD) untuk kompresi & konversi WebP saat upload |

### Batas Upload Gambar

Upload gambar (produk, hero slider, banner) menerima file hingga **5MB**. Semua gambar otomatis dikompres dan dikonversi ke WebP oleh `App\Services\ImageOptimizer` (kualitas & lebar maksimum diatur di `config/image.php`). Agar file besar dapat diterima server, pastikan konfigurasi berikut pada runtime PHP (php.ini) dan web server (nginx):

```ini
upload_max_filesize = 5M
post_max_size = 10M
memory_limit = 512M
```

```nginx
client_max_body_size 10m;
```

---

## Panduan Instalasi & Setup Lokal

### 1. Salin Konfigurasi Environment
```bash
cp .env.example .env
```
Sesuaikan konfigurasi database PostgreSQL di file `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=palorinjani
DB_USERNAME=postgres
DB_PASSWORD=secret
```

### 2. Pasang Dependensi
```bash
composer install
npm install
```

### 3. Generate Application Key & Storage Link
```bash
php artisan key:generate
php artisan storage:link
```

### 4. Eksekusi Migrasi & Data Seeder
```bash
php artisan migrate:fresh --seed
```

### 5. Jalankan Server Lokal
```bash
php artisan serve
```
Akses aplikasi melalui browser:
* **Halaman Admin Filament:** `http://127.0.0.1:8000/admin`
* **Public REST API:** `http://127.0.0.1:8000/api/v1/products`

---

## Kredensial Default Admin

Setelah menjalankan `php artisan db:seed`, akun administrator superadmin dibuat otomatis dari variabel `.env` (`ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`) ke tabel `admin_users`:

* **URL Login:** `http://127.0.0.1:8000/admin/login`
* **Email:** nilai `ADMIN_EMAIL` di `.env` (contoh: `admin@palorinjani.local`)
* **Password:** nilai `ADMIN_PASSWORD` di `.env`

Role & pengguna panel admin dikelola dari menu **Role & Pengguna** oleh superadmin.

---

## Pengujian Otomatis & Standar Kode

### Menjalankan Test Suite (30 Test Cases)
Seluruh fitur dilindungi oleh pengujian integrasi otomatis (PHPUnit / Pest):
```bash
php artisan test
```

### Memeriksa & Memperbaiki Format Kode (Laravel Pint)
```bash
# Periksa kepatuhan format
./vendor/bin/pint --test

# Otomatis rapikan format kode
./vendor/bin/pint
```

---

## Alur Branching Git & Multi-Agent Protocol

Pengembangan fitur di PaloRinjani mengikuti hierarki branch yang ketat:

```text
main (Production Source of Truth)
 └── dev (Integration / Staging)
      ├── feature/variant-sku-management
      ├── feature/product-media-gallery
      ├── feature/banner-management
      └── feature/public-catalog-api
```

1. **Feature Branch Isolation:** Setiap fitur baru harus dibuat dari branch `dev` (`git checkout -b feature/<nama-fitur> dev`).
2. **Anti-Overengineering Code Audit:** Setelah implementasi dan tes selesai, lakukan audit kode terhadap `git diff dev` untuk memastikan prinsip KISS & YAGNI terpenuhi sebelum dimerge ke `dev`.
3. **Merge ke Main:** Branch `dev` yang stabil dan teruji digabungkan ke `main` secara berkala.

---

## Struktur Direktori Backend

```text
backend/
├── app/
│   ├── Filament/
│   │   └── Resources/
│   │       ├── Banners/              # Resource Banner Promosi & Carousel
│   │       ├── Categories/           # Resource Kategori
│   │       └── Products/             # Resource Produk, Form, Tabel, & VariantsRelationManager
│   ├── Http/
│   │   ├── Controllers/Api/V1/       # REST API Controllers (Banner, Category, Product)
│   │   └── Resources/Api/V1/         # Eloquent API Transformers & Formatters
│   ├── Models/
│   │   ├── Banner.php                # Model Banner Promosi
│   │   ├── Category.php              # Model Kategori (Tree Parent-Child)
│   │   ├── Product.php               # Model Produk
│   │   ├── ProductVariant.php        # Model Varian Produk
│   │   ├── Sku.php                   # Model SKU & Stok Fisik
│   │   ├── ProductMedia.php          # Model Galeri Foto Produk
│   │   └── User.php                  # Model Pengguna & Admin
│   └── Providers/
│       └── Filament/
│           └── AdminPanelProvider.php # Konfigurasi Panel, Tema & Warna Brand
├── database/
│   ├── migrations/                   # Skema DDL PostgreSQL
│   └── seeders/                      # Data Awal Dummy untuk Development
├── routes/
│   ├── api.php                       # Definisi Endpoint REST API v1
│   └── web.php                       # Web Routes
└── tests/
    └── Feature/
        ├── Api/V1/                   # Pengujian REST API Katalog & Banners
        └── Filament/                 # Pengujian Integrasi Admin Panel
```

---

**Palo Mountain Goods Engineering Team** — *Built for Reliability and Adventure.*
