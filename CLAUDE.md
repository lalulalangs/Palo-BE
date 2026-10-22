# CLAUDE.md — Palo Mountain Goods (PaloRinjani) Backend

## Ringkasan
- Backend + panel admin e-commerce D2C "Palo Mountain Goods": katalog produk/variant/SKU, cart, checkout, voucher, stok, konten (banner/hero slider), dan REST API v1 untuk storefront.

## Tech stack
- PHP 8.3+ (target runtime 8.5), Laravel 13 (terpasang 13.33), Filament 5.8 (panel admin).
- Database: PostgreSQL 15+ untuk dev, SQLite `:memory:` untuk test. Cache/session/queue dev = `database`.
- Auth: customer via Laravel Sanctum 4 (bearer token); admin via guard session `admin` (tabel `admin_users`).
- intervention/image 4 (driver GD) untuk kompresi + konversi WebP saat upload.
- Vite 8 + Tailwind 4 (aset web/Blade); PHPUnit 12; Laravel Pint 1.27; Laravel Boost 2.10 (MCP).

## Struktur folder
- `app/Filament/Resources/**` — resource panel admin per domain (Products, Categories, Collections, Orders, Vouchers, Roles, dll) + Page.
- `app/Http/Controllers/Api/V1`, `Http/Requests/Api/V1`, `Http/Resources/Api/V1` — REST API v1.
- `app/Models/**` — model Eloquent; `app/Services/**` — logika domain (Cart, Checkout, Inventory, Orders, Shipping, Vouchers).
- `app/Enums/AdminFeature.php` — daftar fitur RBAC admin.
- `database/migrations|factories|seeders`; `routes/api.php` (prefix `/api/v1`), `routes/web.php`.
- `docs/PUBLIC-API-CONTRACT.md` — kontrak resmi API v1 (master, dipakai frontend).
- `lang/` — lokalisasi id (`id.json`) + override vendor Filament.

## Arsitektur & alur data
- Storefront (Next.js) → REST `/api/v1/*` → Controller → FormRequest validasi → Service → Model/PostgreSQL → API Resource.
- Respons JSON memakai envelope `{success, message (Bahasa Indonesia), data}` dengan `pagination` (+`meta`) untuk list; 12/13 controller membangunnya manual, hanya `BrandController` yang berbeda.
- Error JSON dinormalisasi di `bootstrap/app.php`: 4xx/5xx selalu dapat `success:false`; `ValidationException` → `{message bersih, errors}`.
- Auth customer: Sanctum (`auth:sanctum`). Auth admin: Filament + guard `admin`; kolom password `password_hash` (dipetakan via `getAuthPassword()`).
- Guest cart disimpan di Cache (prefix `cart:session:`, TTL 30 hari), bukan DB; token dikirim via header `X-Guest-Token`/cookie.
- Checkout: `GuestCheckoutService` / `RegisteredCheckoutService`. Stok & transisi status dijaga via `DB::transaction` + `lockForUpdate` di `OrderTransitionService` dan `VoucherService::validateAndLock`. Pembayaran: record `Payment` status `pending`; string `'midtrans'` hanya nilai kolom `gateway` belum ada SDK/integrasi asli.
- `SkuObserver` mencatat `StockMovement` otomatis; flag `StockMovementService::$isRecordingMovement` mencegah pencatatan ganda.

## Perintah penting
- Setup awal: `composer setup` (install, copy .env, key, migrate, npm install, build).
- Dev semua proses: `composer dev` (memanggil `php artisan dev`; lihat `php artisan dev:list`).
- Server saja: `php artisan serve` — admin `/admin`, API `/api/v1`.
- Aset frontend: `npm run dev`, `npm run build`.
- Test semua: `php artisan test` atau `vendor/bin/phpunit`.
- Test satu file/kelas: `php artisan test tests/Feature/Api/V1/CatalogApiTest.php`; satu method: tambahkan `--filter=test_can_get_active_banners`.
- Format kode: `vendor/bin/pint` (dry-run `vendor/bin/pint --test`; AGENTS minta `vendor/bin/pint --dirty --format agent`).
- Migrasi + seed: `php artisan migrate:fresh --seed` (seeder membuat superadmin dari `ADMIN_EMAIL`/`ADMIN_NAME`/`ADMIN_PASSWORD`).

## Konvensi kode
- Bahasa: pesan error, label UI, dan komentar naratif memakai Bahasa Indonesia; nama kelas/variabel teknis Inggris.
- Validasi selalu lewat FormRequest (`app/Http/Requests/Api/V1`); `authorize()` = true, `rules()` + `messages()` kustom.
- Respons dibangun manual `response()->json([...])` + API Resource; Resource menyertakan field `formatted_*` (mis. `Number::currency($x, 'IDR', 'id')`, harga juga `base_price` numerik).
- Logika bisnis di kelas Service per domain; controller tipis (query + delegasi service).
- Filament: gating fitur via `AdminFeature` enum + trait `HasAdminFeatureAccess` (`canAccess`), override `Gate::before` di `AppServiceProvider`; redirect form pakai trait `RedirectsToIndexAfterSubmission`.
- Gambar disimpan sebagai path relatif, diubah ke URL publik via `Storage::disk('public')->url(...)`.
- Test: PHPUnit class-based (bukan Pest), `RefreshDatabase`, nama method `test_snake_case`. Test Filament pakai `Livewire::actingAs($user, 'admin')` + `Livewire::test(...)`.

## Gotchas
- Test selalu SQLite in-memory (`phpunit.xml`); jangan andalkan perilaku khusus PostgreSQL (JSONB, `lockForUpdate`) di test.
- DB dev PostgreSQL: `.env.example` `DB_CONNECTION=pgsql`; `config/database.php` fallback default sqlite. README menyebut `DB_DATABASE=palorinjani`/user `postgres`, sedangkan `.env.example` memakai `palorinjani_db`/`palorinjani` — TODO: perlu konfirmasi nilai final.
- `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD` dipakai `RoleAndAdminUserSeeder` tetapi TIDAK ada di `.env.example`; tambahkan manual agar kredensial login `/admin` konsisten.
- Kolom password bernama `password_hash` (bukan `password`) di `users` dan `admin_users`.
- Upload gambar: batas default `config/image.php` (kualitas 82, max width 2048, max 5120 KB); `ImageOptimizer` fallback menyimpan file asli bila konversi WebP gagal. Pastikan `upload_max_filesize`/`post_max_size` php.ini sesuai.
- Route `api/v1/admin/hero-sliders/*` (`AdminHeroSliderController`) BELUM dilindungi auth — ditandai TODO di `routes/api.php`.
- Branch aktif = `devel` (branch feature banyak; alur mengikuti README: feature → `devel` → `main`). Belum ada CI (`.github` tidak ada); remote GitHub `lalulalangs/Palo-BE`.
- Cache/session/queue dev = driver `database`: jalankan migrasi sebelum `php artisan serve`/`composer dev`.
- Jangan edit artefak generated: `vendor/`, `node_modules/`, `composer.lock` (gunakan composer), `.phpunit.result.cache`.
