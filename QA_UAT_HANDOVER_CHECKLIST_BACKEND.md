# QA & Pre-Handover Verification Checklist (Backend)
**Proyek:** Palo Rinjani (Palo Mountain Goods) Backend  
**Lingkungan:** Laravel 13.x | Filament Admin v5.8+ | PostgreSQL 15+ (SQLite in-memory test harness)  
**Tanggal Verifikasi Awal:** 31 Oktober 2026  
**Tanggal Revalidasi Independen:** 31 Oktober 2026  
**Status:** **PASS dengan 6 catatan PARTIAL** — lihat §5 dan §4 (item manual wajib).

> **Riwayat revalidasi.** Versi pertama dokumen ini memuat metrik yang tidak reproducible (598 test / 86 file), ±25 nama method test yang tidak ada di repo, dan 4 klaim faktual yang salah (rate limiter, `.env` DOKU, daftar kurir, kolom voucher). Dokumen ini sudah dikoreksi berdasarkan eksekusi ulang nyata dan pembacaan kode. Bagian yang tidak punya bukti otomatis ditandai **PARTIAL**, bukan PASS.

---

## 1. Metrik Kuantitatif (dihitung ulang, bukan diklaim)

| Komponen | Jumlah | Bukti |
|---|:---:|---|
| API Endpoints | **41 rute** | `php artisan route:list` (`api/v1/*`, termasuk 10 rute admin-banner) |
| Filament Admin Routes | **35 rute** | `php artisan route:list` (`admin/*`) |
| Web & Utility Routes | **16 rute + 1 sanctum/csrf** | `routes/web.php`, health, dsb. (5 perintah terjadwal **bukan** route) |
| Eloquent Models | **28** | `app/Models/` |
| Database Migrations | **47** | `database/migrations/` |
| Domain Services | **15** | `app/Services/` |
| Form Requests | **14** | `app/Http/Requests/Api/V1/` |
| API Resources | **11** | `app/Http/Resources/Api/V1/` |
| Filament Pages / Widgets | **3 / 4** | `app/Filament/Pages`, `app/Filament/Widgets` |
| Test Files | **85** (81 Feature + 4 Unit) | `find tests -name '*Test.php'` |
| Test Suite | **599 test, 3.319 assertion, 0 fail/error/warning** | `php artisan test --compact` (28–29 detik) |

Dua test scaffold bawaan Laravel (`tests/Unit/ExampleTest.php` berisi `assertTrue(true)` dan `tests/Feature/ExampleTest.php` yang hanya `assertStatus(200)`) **dihapus** saat revalidasi karena ikut terhitung "hijau" tanpa menguji perilaku.

---

## 2. Hasil Audit per Layer

### 2.1 Autentikasi, Profil, & Keamanan API

- **Sanctum Authentication:** bearer token; token tamu dirotasi ke akun saat login (`mergeGuestCartIfPresent()`).
- **Anti-IDOR:**
  - Orders: `CheckoutController::index()/show()` memakai `->where('user_id', $user->id)`; akses order user lain → 404 dan body tidak memuat nomor order korban (`RegisteredCheckoutApiTest::test_user_cannot_view_another_users_order`).
  - Guest cancel: verifikasi `guest_reference_code` → 403 (`GuestCancelOrderTest::test_guest_cannot_cancel_order_with_invalid_reference_code`).
  - Cart: terisolasi per `user_id` / token hash (`DeepAuditVerificationTest::test_cart_items_isolated_between_different_users`).
- **Rate Limiting (angka nyata, `AppServiceProvider::configureRateLimiting`):**

| Limiter | Batas | Kunci |
|---|---:|---|
| `auth` | 69/menit | IP |
| `cart` | 69/menit | user / `X-Guest-Token` / IP |
| `guest` | 69/menit | user / `X-Guest-Token` / IP |
| `guest.orders` | 5/menit | IP |
| `checkout` | 69/menit | user / IP |
| `vouchers` | 69/menit | user / `X-Guest-Token` / IP |
| `vouchers.index` | 69/menit | user / IP |
| `shipping.destinations` | 69/menit | user / IP |
| `catalog` | 240/menit | user / `X-Guest-Token` / IP |

  Webhook DOKU memakai `throttle:120,1` literal di `routes/api.php`, **bukan** named limiter `doku` (limiter itu tidak ada). Yang punya test rate-limit hanya `auth` (`CustomerAuthStrictQaTest::test_rate_limiting_enforces_69_requests_per_minute_returning_429`) dan `vouchers` (`VoucherApiTest::test_apply_enforces_69_requests_per_minute`).

### 2.2 Katalog, Produk, Varian (SKU), Kategori, & Media

- Hierarki kategori parent-child & penolakan produk non-aktif: `CatalogApiTest::test_can_get_nested_categories`, `test_product_detail_returns_404_for_unknown_or_inactive_product`.
- Relasi `Product → ProductVariant → Sku`; `base_price` + `price_override`.
- Snapshot `order_items` non-nullable + `sku_id` `nullOnDelete()` (`RegisteredCheckoutApiTest::test_order_creation_creates_immutable_item_snapshots`).
- Media: maks 8 item, konversi WebP, `maxSize(config('image.max_upload_kb'))` = 2048 KB.

### 2.3 Cart, Checkout, Voucher, & Ekspedisi

- **Available stock:** `max(0, stock - reserved_stock)`, reserved = order pending dengan `stock_locked_until > now()` (`RegisteredCheckoutService`).
- **Voucher (nama kolom nyata):** `quota`, `used_count`, `start_date`, `end_date`, `min_purchase`, `is_active`.
  - `quota <= 0` berarti **tanpa batas** (unlimited), bukan "habis" (`Voucher::hasQuotaRemaining()`).
  - Kuota dikembalikan atomik saat order dibatalkan/expired (`RegisteredCheckoutApiTest::test_order_cancellation_refunds_voucher_usage_counter`).
  - Boundary Rp 99.999 (422) vs Rp 100.000 (200, diskon 25.000) — `DeepAuditVerificationTest::test_voucher_exact_minimum_purchase_boundary`.
  - Expired/habis → 422 (`VoucherApiTest::test_apply_rejects_expired_voucher_with_422`, `test_apply_rejects_when_quota_exhausted_with_422`).
- **RajaOngkir:**
  - Berat aktual vs volumetrik.
  - Kurir aktif berasal dari `app_settings` (`jne:jnt` pada seeder; JNE REG/YES, J&T EZ). **POS & TIKI tidak dikonfigurasi.**
  - Fail-closed 503 saat penyedia down (`RegisteredCheckoutApiTest::test_shipping_quote_returns_503_when_courier_service_down`).
  - **Tarif cache basi (`SOURCE_STALE`, maks 7 hari) hanya untuk tampilan.** Checkout menolak order dengan 503 (`RegisteredCheckoutApiTest::test_checkout_fails_closed_with_503_when_only_stale_shipping_cache_is_available`) — guard ini ditambahkan saat revalidasi karena sebelumnya checkout bisa memakai tarif 2 hari lalu.
  - Destinasi memakai tabel lokal `shipping_destinations` (Zero-HIT); klaim "sub-15 ms" **tidak** punya benchmark tersimpan.

### 2.4 Transaksi, DOKU, & Mutasi Stok

- **Concurrency (jujur):** kode memakai `DB::transaction` + `Sku::lockForUpdate()` (`RegisteredCheckoutService.php:131`) dan `OrderTransitionService`/`StockMovementService`. **Namun test berjalan di SQLite in-memory, di mana row lock praktis no-op.**
  - Yang benar-benar diuji otomatis adalah invariannya secara **sekuensial**:
    - order pending pembeli pertama me-soft-lock unit terakhir → pembeli kedua 409 `stock_unavailable` + tidak ada overselling (`test_second_buyer_of_last_unit_is_rejected_with_409_while_first_order_is_pending`);
    - kuota voucher 1 → checkout kedua 422, tetap 1 order (`test_second_checkout_with_last_voucher_quota_is_rejected_with_422`);
    - qty 50 vs stok 10 → 409, 0 order (`test_checkout_rejects_with_409_when_stock_is_insufficient`).
  - **Tidak ada test paralel dua proses.** Verifikasi race condition nyata harus dijalankan manual di staging PostgreSQL (lihat §4 item 8).
- **Webhook DOKU:**
  - HMAC-SHA256 + `hash_equals` + cek Client-Id; signature palsu → 401 (`test_rejects_notification_with_invalid_signature_with_401`).
  - Idempoten penuh lewat HTTP: POST 2× → 1 `PaymentWebhookLog`, stok 10→9 sekali, history `paid` sekali (`test_success_notification_marks_order_as_paid_and_is_idempotent`).
  - Late settlement pada order `cancelled` (cabang kode sama dengan `expired`) → payment SETTLEMENT + flag rekonsiliasi, order tidak dibuka ulang (`test_late_settlement_marks_payment_paid_without_reopening_cancelled_order`).
  - Amount mismatch tidak melunasi (`test_amount_mismatch_does_not_mark_order_as_paid`).
  - Log penolakan DOKU **tidak lagi** menulis `response_body` mentah (berpotensi memuat nama/email/telepon pembeli). Hanya `invoice_number`, `request_id`, status, dan `error_messages`.
- **State machine:** `pending → paid → processing → shipped → completed/cancelled`, stok fisik berkurang di `markAsPaid()`, kembali via `TYPE_CANCELLATION` (`OrderTransitionServiceTest::test_cancel_order_restores_stock_if_previously_paid`), guard stok negatif (`StockMovementServiceTest::test_record_movement_prevents_negative_stock_and_rolls_back`).

### 2.5 Filament Admin

- RBAC per halaman: 13 URL diuji 403 (tanpa izin) **dan** 200 (dengan izin) — `AdminFeatureAccessTest::test_feature_page_access_control`.
- Superadmin: seluruh 13 URL dari data provider yang sama (`test_superadmin_can_access_every_feature_page`; sebelumnya `/admin/pengaturan-ekspedisi` terlewat dari daftar).
- Dashboard kas besar: render + angka rupiah (`InventoryCashflowDashboardTest::test_superadmin_can_access_dashboard_and_see_filters_and_widgets`, `test_kas_besar_stats_overview_renders_data_for_superadmin`). Klaim "zero-parenthesis" adalah keputusan desain, bukan hasil test.

### 2.6 Scheduler & Konfigurasi

| Command | Jadwal |
|---|---|
| `orders:expire-unpaid` | setiap menit |
| `orders:expire-unconfirmed-cod` | setiap jam (`withoutOverlapping`) |
| `payments:reconcile-doku` | harian 02:00 |
| `pii:anonymize` | harian |

- **`.env` DOKU (diperbaiki saat revalidasi):** kunci yang dibaca `config/doku.php` adalah `DOKU_PAYMENT_DUE_MINUTES`. Sebelumnya `.env` lokal memakai `DOKU_DUE_MINUTES` sehingga nilai lokal diabaikan dan jendela pembayaran jatuh ke default 60 menit.

---

## 3. Matriks Pengujian (nama method = yang benar-benar ada di repo)

| ID | Endpoint / Unit | Skenario | Ekspektasi | Metode Verifikasi | Status |
|:---:|---|---|---|---|:---:|
| SEC-01 | `POST /auth/register` | Registrasi valid; email `TEST@EXAMPLE.COM` | 201, email lowercase, token terbit | `CustomerAuthTest::test_customer_can_register_successfully` + `CustomerAuthStrictQaTest::test_registration_email_case_insensitivity_prevents_uniqueness_bypass_and_database_crash` | PASS |
| SEC-02 | `POST /auth/login` | Password salah | 401 | `CustomerAuthTest::test_login_fails_with_invalid_password` | PASS |
| SEC-03 | `POST /auth/login` | 70 request/menit | request ke-70 → 429 | `CustomerAuthStrictQaTest::test_rate_limiting_enforces_69_requests_per_minute_returning_429` | PASS |
| SEC-04 | `GET /auth/me` | Token valid | 200, profil | `CustomerAuthTest::test_authenticated_customer_can_access_me_profile` | PASS |
| SEC-05 | `POST /auth/logout` | Logout | 200, token terhapus | `CustomerAuthTest::test_authenticated_customer_can_logout` + `test_multi_device_logout_isolation` | PASS |
| IDOR-01 | `GET /orders/{no}` | User A buka order User B | 404, body tanpa data korban | `RegisteredCheckoutApiTest::test_user_cannot_view_another_users_order` | PASS |
| IDOR-02 | `POST /guest/cancel-order` | Kode referensi salah | 403 | `GuestCancelOrderTest::test_guest_cannot_cancel_order_with_invalid_reference_code` | PASS |
| IDOR-03 | `GET /cart` | Isolasi cart antar user | Cart B kosong | `DeepAuditVerificationTest::test_cart_items_isolated_between_different_users` | PASS |
| CAT-01 | `GET /products` | Filter kategori/search/pagination | 200, envelope | `CatalogApiTest::test_can_filter_products_by_category` + `test_can_search_and_filter_products` | PASS |
| CAT-02 | `GET /products/{slug}` | Detail produk | 200, varian+SKU+media | `CatalogApiTest::test_can_get_product_detail_with_variants_skus_and_media` | PASS |
| CAT-03 | `GET /products/{slug}` | Produk non-aktif | 404 | `CatalogApiTest::test_product_detail_returns_404_for_unknown_or_inactive_product` | PASS |
| CAT-04 | `GET /categories` | Hierarki | 200, tree | `CatalogApiTest::test_can_get_nested_categories` | PASS |
| BAN-01 | `GET /banners` | Banner publik | 200, aktif & terurut | `HeroSliderTest::test_public_index_returns_only_active_ordered_with_exact_shape` | PASS |
| BAN-02 | `/admin/banners` | CRUD + RBAC | 200/201; tanpa izin 403 | `DeepAuditVerificationTest::test_admin_banners_alias_requires_authentication`, `…_rejects_customer_with_403`, `…_rejects_admin_without_hero_sliders_permission`, `…_crud_workflow` | PARTIAL (lihat §5) |
| CRT-01 | `POST /cart/items` | Tambah item | 200, cookie tamu | `CartApiTest::test_guest_can_add_item_to_cart_and_receives_guest_token_cookie` | PASS |
| CRT-02 | `POST /cart/items` | qty > available | 422 `errors.quantity` | `CartApiTest::test_guest_cannot_add_item_exceeding_available_stock` | PASS |
| CRT-03 | `PATCH /cart/items/{sku}` | Ubah qty | 200, subtotal dihitung ulang | `CartApiTest::test_guest_can_update_item_quantity` | PASS |
| CRT-04 | `DELETE /cart/items/{sku}` | Hapus item | 200, item hilang | `CartApiTest::test_guest_can_remove_item_from_cart` | PASS |
| VOU-01 | `POST /vouchers/apply` | Voucher fixed valid | 200, diskon akurat | `VoucherApiTest::test_apply_fixed_discount_successfully` | PASS |
| VOU-02 | `POST /vouchers/apply` | Boundary Rp 99.999 vs 100.000 | 422 vs 200 | `DeepAuditVerificationTest::test_voucher_exact_minimum_purchase_boundary` | PASS |
| VOU-03 | `POST /vouchers/apply` | Expired / kuota habis | 422 | `VoucherApiTest::test_apply_rejects_expired_voucher_with_422` + `test_apply_rejects_when_quota_exhausted_with_422` + `RegisteredCheckoutApiTest::test_checkout_rejects_expired_voucher` | PASS |
| SHP-01 | `POST /shipping/quote` | Tarif multi-kurir | 200, JNE REG 14.000 & J&T EZ 13.500 | `ShippingApiTest::test_quote_returns_services_with_metadata` | PASS |
| SHP-02 | `POST /shipping/quote` | Penyedia down | 503 fail-closed | `RegisteredCheckoutApiTest::test_shipping_quote_returns_503_when_courier_service_down`; tarif basi ditolak di checkout: `test_checkout_fails_closed_with_503_when_only_stale_shipping_cache_is_available` | PASS |
| CHK-01 | `POST /checkout/orders` | Checkout terdaftar | 201, snapshot, link DOKU | `RegisteredCheckoutApiTest::test_registered_user_can_create_order_successfully` | PASS |
| CHK-02 | `POST /checkout/orders` | Stok terakhir & kuota voucher | 201 lalu 409/422, tanpa overselling | `test_checkout_rejects_with_409_when_stock_is_insufficient`, `test_second_buyer_of_last_unit_is_rejected_with_409_while_first_order_is_pending`, `test_second_checkout_with_last_voucher_quota_is_rejected_with_422` | PARTIAL (sekuensial, bukan paralel) |
| CHK-03 | `POST /checkout/orders` | Harga berubah | 409 `price_changed`, 0 order | `RegisteredCheckoutApiTest::test_checkout_detects_price_change_and_returns_409_price_changed` | PASS |
| CHK-04 | `POST /guest/cod-checkout` | COD tamu | 201 `awaiting_confirmation` + link WA | `CodCheckoutApiTest::test_guest_user_can_checkout_with_cod` | PASS |
| CHK-05 | `POST /guest/whatsapp-checkout` | Lead WhatsApp | 201 + log audit + link WA | `GuestCheckoutApiTest::test_guest_can_checkout_via_whatsapp_with_explicit_items` | PASS (lihat §5) |
| PAY-01 | `POST /payments/webhook/doku` | Signature valid | 200, `paid`, stok −1 | `DokuWebhookTest::test_success_notification_marks_order_as_paid_and_is_idempotent` | PASS |
| PAY-02 | `POST /payments/webhook/doku` | Signature palsu | 401 | `DokuWebhookTest::test_rejects_notification_with_invalid_signature_with_401` | PASS |
| PAY-03 | `POST /payments/webhook/doku` | Webhook 2× | 200, 1 log, stok & status tak dobel | `DokuWebhookTest::test_success_notification_marks_order_as_paid_and_is_idempotent` | PASS |
| PAY-04 | `POST /payments/webhook/doku` | Bayar setelah order cancelled/expired | `late_settlement`, payment SETTLEMENT, order tidak dibuka | `DokuWebhookTest::test_late_settlement_marks_payment_paid_without_reopening_cancelled_order` + `test_settlement_after_lock_expiry_flags_reconciliation_when_stock_is_gone` | PASS |
| INV-01 | `StockMovementService` | Restock +15 & penjualan −3 | `stock_before/after/type` akurat | `StockMovementServiceTest::test_record_movement_increments_stock_on_restock`, `test_record_movement_decrements_stock_on_sale` | PASS |
| INV-02 | `cancelOrder` | Batal setelah dibayar | `TYPE_CANCELLATION`, stok kembali | `OrderTransitionServiceTest::test_cancel_order_restores_stock_if_previously_paid` | PASS |
| INV-03 | Opname Filament | Apply opname | Mutasi `opname_adjustment` | `StockOpnameResourceTest::test_apply_opname_from_edit_page` + `StockMovementServiceTest::test_apply_opname_updates_stock_and_creates_adjustment_movements` | PASS |
| FIL-01 | `/admin/*` | Staf tanpa izin | 403 di 13 URL | `AdminFeatureAccessTest::test_feature_page_access_control` | PASS |
| FIL-02 | `/admin/*` | Superadmin | 200 di 13 URL | `AdminFeatureAccessTest::test_superadmin_can_access_every_feature_page` | PASS |
| FIL-03 | Dashboard | Render + angka rupiah | Rp 850.000 / Rp 150.000 tampil | `InventoryCashflowDashboardTest::test_superadmin_can_access_dashboard_and_see_filters_and_widgets`, `test_kas_besar_stats_overview_renders_data_for_superadmin` | PASS |
| CRN-01 | `orders:expire-unpaid` | Pending lewat batas | `expired`, kuota voucher pulih | `ExpireUnpaidOrdersCommandTest::test_expired_pending_order_becomes_expired_and_voucher_quota_restored` | PASS |
| CRN-02 | `pii:anonymize` | Retensi tamu | Pesan tamu lama ter-anonimisasi | `PiiAnonymizationTest::test_old_guest_checkout_message_is_anonymized` | PASS |

---

## 4. Sanity Check Manual Production (WAJIB sebelum go-live)

1. **Cron `schedule:run`** di VPS; uji order pending → otomatis `expired`.
2. **Kredensial & callback DOKU:** `DOKU_ENV=production`, `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY`, `DOKU_PAYMENT_DUE_MINUTES`, Notification URL `https://api.palorinjani.com/api/v1/payments/webhook/doku`.
3. **RajaOngkir:** API key + kuota harian; origin di `/admin/pengaturan-ekspedisi`; pastikan kurir aktif sesuai daftar yang benar-benar dilayani (dari Bayan: JNE, J&T, Lion, POS).
4. **Nomor WhatsApp CS** di `/admin/pengaturan-media-sosial`.
5. **Storage:** `php artisan storage:link`, permission `www-data`, uji upload gambar produk.
6. **Rotasi kredensial superadmin** seeder.
7. **Transaksi nyata nominal kecil** (QRIS live) → webhook masuk, order `paid`, stok berkurang.
8. **Uji race condition di staging PostgreSQL** (dua proses paralel, stok = 1, dua user; kuota voucher = 1, dua user). Ini **tidak** bisa dibuktikan oleh suite SQLite.
9. **Tentukan jalur auth admin API banner:** endpoint `/api/v1/admin/*` memakai `auth:sanctum`, sedangkan login admin memakai guard `admin` dan tidak ada endpoint penerbit token AdminUser. Panel admin memakai Filament, jadi endpoint ini hanya terjangkau lewat token manual/CLI.
10. **Kebijakan stok WhatsApp lead:** `GuestCheckoutService::checkout()` hanya memeriksa `is_active` (tidak memeriksa `available_stock`) dan tidak mengunci stok — order untuk SKU stok 0 tetap dibuat, konfirmasi dilakukan CS. Pastikan ini keputusan bisnis resmi atau tambahkan validasi.

---

## 5. Catatan Revalidasi (temuan yang dikoreksi)

| # | Temuan | Status setelah revalidasi |
|---|---|---|
| R-1 | Klaim "2 user serentak → 409" tidak didukung: test lama berurutan, menguji kuota voucher, dan mengharapkan 422 | Klaim dikoreksi; ditambah test sekuensial unit-terakhir (409) + kuota (422); PARALEL tetap manual (§4.8) |
| R-2 | 25 nama method test di matriks tidak ada di repo | Semua diganti nama method nyata (diverifikasi `grep`) |
| R-3 | Daftar kurir "JNE, POS, TIKI" | Kurir aktif = `jne:jnt` (dapat diubah via `app_settings`) |
| R-4 | Angka rate limiter (15/30/60, limiter `doku`) | Tabel §2.1 memakai angka nyata; webhook `throttle:120,1` |
| R-5 | `.env` memakai `DOKU_DUE_MINUTES` (tidak dibaca kode) | `.env` diperbaiki ke `DOKU_PAYMENT_DUE_MINUTES` |
| R-6 | Kolom voucher `usage_limit`/`starts_at`/`expires_at`; "kuota 0 = habis" | Kolom nyata `quota`/`start_date`/`end_date`; `quota <= 0` = unlimited |
| R-7 | Checkout bisa memakai tarif ongkir cache basi (7 hari) | Guard 503 ditambahkan + test |
| R-8 | Log penolakan DOKU menulis body mentah (PII) | Field `response_body` dihapus |
| R-9 | Superadmin hanya diuji 12 dari 13 modul | Test mengambil URL dari data provider (13, termasuk pengaturan ekspedisi) |
| R-10 | Test scaffold `assertTrue(true)` & `assertStatus(200)` ikut dihitung | Dihapus |

**Sisa keterbatasan:** harness test SQLite tidak merepresentasikan row lock PostgreSQL; klaim "sub-15 ms" destinasi tidak punya benchmark; endpoint admin-banner belum punya jalur token produksi.
