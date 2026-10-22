# Rencana Implementasi Payment Gateway DOKU (DOKU Checkout Hosted)

| Atribut | Nilai |
|---|---|
| Dasar Keputusan | Konfirmasi PM (20-10-2026): payment gateway = **DOKU** |
| Target Temuan | [AUDIT-VALIDATION.md](../docs/AUDIT-VALIDATION.md) — S-03 (QR/VA palsu) dan S-04 (webhook pembayaran nihil / rekonsiliasi), keduanya sebelumnya *blocked: vendor belum dipilih* |
| Basis Kode | Branch `sandbox/doku` (turunan `integration/audit-and-cod` / commit `a6c70bf`) |
| Status Rencana | **Selesai diimplementasikan** — 492 test lulus, Pint bersih. Lihat §13 untuk catatan kesesuaian implementasi. |
| Keputusan Produk | **DOKU Checkout (hosted payment page)** + jendela pembayaran **60 menit** |
| Prinsip Desain | KISS & YAGNI: satu endpoint keluar, satu endpoint masuk, tanpa dependency baru, tanpa tabel baru |

---

## 1. Ringkasan & Konteks

PM sudah mengonfirmasi DOKU sebagai payment gateway. Cek read-only terhadap repo menunjukkan **integrasi belum ada sama sekali** (0 referensi DOKU), sehingga keputusan ini membuka blocker **S-03/S-04** tetapi tidak mengurangi pekerjaan: seluruh jalur pembayaran online masih placeholder "hubungi Customer Service".

Rencana ini memilih **DOKU Checkout (hosted page)** karena:

- hanya butuh **satu panggilan keluar** (`POST /checkout/v1/payment`) + **satu endpoint masuk** (HTTP notification);
- semua kanal (VA, QRIS, e-wallet, kartu) dilayani halaman DOKU, tanpa membangun UI pembayaran di storefront;
- tidak memerlukan SKD/dependency baru — cukup `Http` facade bawaan Laravel dan `hash_hmac()`;
- alternatif SNAP (VA/QRIS langsung di halaman sendiri) menuntut token B2B, RSA keypair, dan SDK resmi (`doku/doku-php-library` + `firebase/php-jwt` + `sodium_compat`), yang jauh melampaui kebutuhan saat ini (YAGNI).

**Keputusan turunan yang dipilih pengguna:** jendela pembayaran **60 menit** (bukan 15 menit), dengan konsekuensi `stock_locked_until` dan `orders:expire-unpaid` ikut menyesuaikan.

---

## 2. Kondisi Kode Saat Ini (Terverifikasi)

| Area | Kondisi | Bukti |
|---|---|---|
| Nilai kolom `gateway` | Hanya `cod` dan `manual`; tidak ada `midtrans`/`doku` | [RegisteredCheckoutService.php:347](../app/Services/Checkout/RegisteredCheckoutService.php#L347) |
| Instruksi bayar online | Teks netral "hubungi Customer Service", QR/VA `null` (mitigasi S-03 sudah jalan) | [RegisteredCheckoutService.php:410](../app/Services/Checkout/RegisteredCheckoutService.php#L410), [PaymentInstructionNeutralTest.php](../tests/Feature/Api/V1/PaymentInstructionNeutralTest.php) |
| Kontrak gateway | Interface placeholder, tanpa implementasi & tanpa binding | [PaymentGatewayContract.php](../app/Contracts/PaymentGatewayContract.php), [AppServiceProvider.php](../app/Providers/AppServiceProvider.php) |
| Tabel penunjang | `payments` + `payment_webhook_logs` ada (`external_event_id` UNIQUE) | [migrasi payments](../database/migrations/2026_10_07_100010_create_payments_table.php), [migrasi webhook logs](../database/migrations/2026_10_07_100011_create_payment_webhook_logs_table.php) |
| Jalur lunas manual | `markAsPaid()` + aksi Filament "Konfirmasi Pembayaran" (bridge A-02) | [OrderTransitionService.php:35](../app/Services/Orders/OrderTransitionService.php#L35), [OrderActions.php:35](../app/Filament/Resources/Orders/Actions/OrderActions.php#L35) |
| Kedaluwarsa pesanan | `orders:expire-unpaid` tiap menit, mengikuti `stock_locked_until` | [routes/console.php:22](../routes/console.php#L22), [ExpireUnpaidOrders.php](../app/Console/Commands/ExpireUnpaidOrders.php) |
| Jendela bayar | 15 menit, hardcoded | [RegisteredCheckoutService.php:274](../app/Services/Checkout/RegisteredCheckoutService.php#L274) |
| Outbound HTTP | Nol pemakaian `Http::` di seluruh `app/` — ini integrasi eksternal pertama | hasil grep |
| Baseline test | 28 test payment/checkout terkait lulus | `php artisan test` (dijalankan saat cek) |

### Dua risiko desain yang wajib ditutup rencana ini

1. **Pembayaran masuk setelah pesanan dibatalkan.** `orders:expire-unpaid` membatalkan order + mengembalikan stok begitu `stock_locked_until` lewat. Jika settlement DOKU datang setelah itu, `markAsPaid()` melempar `LogicException` (status bukan `pending`) → uang diterima, pesanan mati, stok bisa sudah terjual ke order lain. Jendela 60 menit memperkecil peluang, **tidak menghilangkannya**.
2. **Retry notifikasi DOKU.** DOKU mengulang kiriman bila respons bukan `200`. Tanpa dedupe, `markAsPaid()` melempar error → respons 500 → retry tanpa akhir.

---

## 3. Keputusan Desain (ADR Ringkas)

| ID | Keputusan | Alasan | Konsekuensi |
|---|---|---|---|
| D1 | Pakai **DOKU Checkout hosted** (`POST /checkout/v1/payment`) | Satu endpoint, semua kanal, tanpa UI pembayaran baru | FE wajib redirect ke `payment_url`; metode dipilih di halaman DOKU |
| D2 | **Tanpa SDK/dependency baru**, pakai `Http` facade + `hash_hmac()` | SDK resmi hanya untuk SNAP dan menambah 2 dependency (butuh approval terpisah) | Kita memelihara sendiri ~120 baris kode signature/HTTP |
| D3 | Jendela bayar **60 menit** = `payment_due_date` DOKU = `stock_locked_until` | Menghindari "bayar setelah batal"; satu angka untuk semua | Ubah 15 → 60 di service, kontrak API, komentar & test |
| D4 | `invoice_number` DOKU = `orders.order_number` (sudah unik & immutable) | Idempotensi & rekonsiliasi sederhana; tidak perlu kolom baru | `payments.gateway_transaction_id` = nomor order |
| D5 | `payment_url` disimpan di `payments.raw_response` (jsonb), **tanpa migrasi baru** | `raw_response` sudah jadi tempat instruksi; menghindari perubahan skema | FE membaca `data.payment.payment_url` (helper di controller/resource) |
| D6 | Gagal memanggil DOKU = **checkout gagal & rollback**, bukan order menggantung | Uang tidak boleh diterima tanpa transaksi gateway yang valid | Pesan error 502/503 yang jelas + log; customer mencoba lagi |
| D7 | Status `SUCCESS` + order sudah `cancelled/expired` → tandai lunas + **flag rekonsiliasi**, **tidak** membuka order otomatis | Stok mungkin sudah terjual; keputusan refund/reorder harus manusia | Admin melihat `needs_reconciliation` dan menindaklanjuti |
| D8 | Jalur konfirmasi manual admin tetap ada | Jaring operasional bila notifikasi DOKU gagal total | Tidak dihapus, hanya bukan jalur utama |

---

## 4. Alur Target

```
Storefront                    Backend                          DOKU
   |                            |                                |
   |-- POST /checkout/orders -->|                                |
   |   (payment_method=qris)    | validasi + kunci stok 60 menit |
   |                            |-- POST /checkout/v1/payment --->|
   |                            |<-- 200 {payment.url} ----------|
   |                            | simpan Payment(gateway=doku,   |
   |                            |   gateway_transaction_id=order, |
   |                            |   raw_response.payment_url)     |
   |<-- 201 {payment_url} ------|                                |
   |-- redirect ke payment_url ---------------------------------->|
   |                            |                                |
   |                            |      customer membayar         |
   |                            |<-- POST /payments/webhook/doku -|
   |                            | verifikasi signature            |
   |                            | catat PaymentWebhookLog (dedupe)|
   |                            | markAsPaid / flag rekonsiliasi  |
   |                            |-- 200 ------------------------->|
```

Rekonsiliasi harian: `payments:reconcile-doku` memanggil Check Status API DOKU untuk payment `pending` yang sudah lewat jendela.

---

## 5. Rincian Komponen

### 5.1 Konfigurasi & Environment

**Berkas baru:** `config/doku.php`

```php
return [
    'client_id' => env('DOKU_CLIENT_ID'),
    'secret_key' => env('DOKU_SECRET_KEY'),
    'is_production' => (bool) env('DOKU_IS_PRODUCTION', false),
    'base_url' => env('DOKU_IS_PRODUCTION', false)
        ? 'https://api.doku.com'
        : 'https://api-sandbox.doku.com',
    'checkout_path' => '/checkout/v1/payment',
    'status_path' => '/orders/v1/status',
    'notification_path' => env('DOKU_NOTIFICATION_PATH', '/api/v1/payments/webhook/doku'),
    'payment_window_minutes' => (int) env('DOKU_PAYMENT_WINDOW_MINUTES', 60),
    'connect_timeout_seconds' => 3,
    'timeout_seconds' => (int) env('DOKU_TIMEOUT_SECONDS', 10),
];
```

**Berkas yang diubah:** [config/app.php](../config/app.php) (tambah `'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')`, karena saat ini hanya [config/cors.php:28](../config/cors.php#L28) yang membaca `FRONTEND_URL`), [.env.example](../.env.example), [.env.production.example](../.env.production.example)

```dotenv
# DOKU Checkout (payment gateway). Isi dari DOKU Dashboard → Integration.
DOKU_CLIENT_ID=
DOKU_SECRET_KEY=
DOKU_IS_PRODUCTION=false
DOKU_NOTIFICATION_PATH=/api/v1/payments/webhook/doku
DOKU_PAYMENT_WINDOW_MINUTES=60
DOKU_TIMEOUT_SECONDS=10
```

Catatan: `env()` hanya dipanggil di file config (aturan `config.md`). `.env` lokal tidak diubah; tanpa kredensial, checkout online harus gagal dengan pesan jelas (§5.4).

### 5.2 Kontrak Gateway & Binding

**Diubah:** [app/Contracts/PaymentGatewayContract.php](../app/Contracts/PaymentGatewayContract.php) — perjelas signature agar bisa memverifikasi notifikasi DOKU (butuh header, bukan hanya body):

```php
interface PaymentGatewayContract
{
    public function name(): string;                       // 'doku'
    public function isConfigured(): bool;
    public function createPayment(Order $order): array;   // gateway, method, reference, payment_url, instructions
    public function verifySignature(string $payload, array $headers, string $requestTarget): bool;
    public function parseWebhook(string $payload): array; // event_id, type, order_number, status, amount, channel
}
```

**Diubah:** [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php) — `bind(PaymentGatewayContract::class, DokuCheckoutGateway::class)`.

### 5.3 DOKU Client

**Berkas baru:** `app/Services/Payments/DokuCheckoutGateway.php`

Tanggung jawab: membangun payload, menandatangani request, memanggil DOKU, mengurai respons, memverifikasi notifikasi.

**Payload Checkout** (field yang dipakai):

| Field | Nilai dari aplikasi |
|---|---|
| `order.invoice_number` | `order.order_number` |
| `order.amount` | `(int) round(order.total)` — IDR integer |
| `order.currency` | `IDR` |
| `order.callback_url` | `config('app.frontend_url').'/orders/'.$order->order_number` (lihat penambahan `frontend_url` di §5.1) |
| `order.line_items` | item pesanan + ongkir, dengan diskon didistribusikan proporsional; total wajib == `order.amount` (fallback: satu baris "Total pesanan") |
| `payment.payment_due_date` | `config('doku.payment_window_minutes')` |
| `payment.payment_method_types` | hasil mapping `payment_method` (tabel di bawah) |
| `customer.name/email/phone/address/country` | dari user + snapshot alamat |

Mapping metode aplikasi → `payment_method_types` ([sumber resmi](https://developers.doku.com/accept-payments/doku-checkout/configuration/supported-payment-methods.md)):

| `payment_method` (API kita) | Nilai DOKU |
|---|---|
| `qris` | `QRIS` |
| `virtual_account`, `bank_transfer` | `VIRTUAL_ACCOUNT_BCA`, `VIRTUAL_ACCOUNT_BANK_MANDIRI`, `VIRTUAL_ACCOUNT_BRI`, `VIRTUAL_ACCOUNT_BNI`, `VIRTUAL_ACCOUNT_BANK_PERMATA`, `VIRTUAL_ACCOUNT_BANK_CIMB`, `VIRTUAL_ACCOUNT_DOKU` |
| `ewallet` | `EMONEY_OVO`, `EMONEY_DANA`, `EMONEY_SHOPEE_PAY`, `EMONEY_DOKU`, `EMONEY_LINKAJA` |
| `credit_card` | `CREDIT_CARD` |

**Signature (spec DOKU, terverifikasi dari implementasi resmi DOKU):**

```php
$digest = base64_encode(hash('sha256', $rawBody, true));
$raw = "Client-Id:{$clientId}\n"
     . "Request-Id:{$requestId}\n"
     . "Request-Timestamp:{$timestamp}\n"
     . "Request-Target:{$target}\n"
     . "Digest:{$digest}";
$signature = 'HMACSHA256=' . base64_encode(hash_hmac('sha256', $raw, $secretKey, true));
```

- Request dikirim dengan `Http::withHeaders([...])->withBody($json, 'application/json')` agar **byte body yang ditandatangani identik** dengan yang dikirim (`json_encode` sekali, jangan biarkan client meng-encode ulang).
- Header: `Client-Id`, `Request-Id` (UUID), `Request-Timestamp` (ISO-8601 +07:00), `Signature`.
- Timeout: `connectTimeout(3)->timeout(10)`; **tanpa `retry()`** pada `POST` (state-changing, tidak ada idempotency key di DOKU).
- Sukses = HTTP 200 **dan** `message[0] === 'SUCCESS'` **dan** `response.payment.url` ada. Selain itu lempar `PaymentGatewayException` berisi pesan aman (tanpa secret/credential).
- Check Status API (`GET /orders/v1/status/{invoice_number}`) memakai formula sama **tanpa** baris `Digest` (tidak ada body).
- Logging: `Log::warning('payment.doku.request_failed', [...])` dengan invoice + status + potongan respons; **jangan** log `Secret-Key`.

### 5.4 Integrasi Checkout

**Diubah:** [app/Services/Checkout/RegisteredCheckoutService.php](../app/Services/Checkout/RegisteredCheckoutService.php)

- Baris 274: `now()->addMinutes(15)` → `now()->addMinutes(config('doku.payment_window_minutes'))`.
- Blok `else` pembuatan Payment (baris ~340): panggil `$gateway->createPayment($order)` lalu simpan:

```php
$instruction = $gateway->createPayment($order); // HTTP call ke DOKU

Payment::create([
    'order_id' => $order->id,
    'gateway' => $gateway->name(),                       // 'doku'
    'gateway_transaction_id' => $instruction['reference'], // = order_number
    'payment_method' => $paymentMethod,                  // preferensi customer
    'amount' => $grandTotal,
    'status' => Payment::STATUS_PENDING,
    'expired_at' => $order->stock_locked_until,
    'raw_response' => $instruction['instructions'],      // memuat payment_url
]);
```

- Bila `! $gateway->isConfigured()` → `ValidationException`/503 dengan pesan "Pembayaran online belum tersedia, silakan pilih COD atau hubungi CS." (bukan membuat QR/VA palsu).
- Bila DOKU error → exception → `DB::transaction` rollback (order, item, voucher usage, stok tidak tersimpan) dan respons 502 dengan pesan Indonesia.
- Jalur COD tidak berubah; checkout tamu (`GuestCheckoutService`) tetap COD saja.
- `generatePaymentInstructions()` dihapus/diganti (tidak ada lagi instruksi "hubungi CS" untuk pembayaran online).
- Sisa referensi 15 menit disesuaikan: [RegisteredCheckoutService.php:412](../app/Services/Checkout/RegisteredCheckoutService.php#L412), [ExpireUnpaidOrders.php:36-41](../app/Console/Commands/ExpireUnpaidOrders.php#L36), [routes/console.php:14](../routes/console.php#L14), komentar test terkait.

Catatan trade-off: panggilan HTTP berada **di dalam** `DB::transaction` (struktur sekarang) sehingga baris SKU terkunci maksimal ~13 detik bila DOKU lambat. Diterima untuk volume D2C saat ini; alternatif "commit dulu lalu buat transaksi" tidak dipakai karena menghasilkan order tanpa pembayaran (D6).

### 5.5 Endpoint Webhook

**Route baru** di [routes/api.php](../routes/api.php) (tanpa `auth:sanctum`, hanya signature DOKU + throttle longgar):

```php
Route::post('payments/webhook/doku', [PaymentWebhookController::class, 'doku'])
    ->middleware('throttle:120,1');
```

**Berkas baru:** `app/Http/Controllers/Api/V1/PaymentWebhookController.php` (tipis) + `app/Services/Payments/DokuWebhookService.php` (logika).

Alur wajib:

1. Baca raw body `$request->getContent()` **sebelum** parsing.
2. Verifikasi signature: header `Client-Id`, `Request-Id`, `Request-Timestamp`, `Signature`; `Request-Target` = `config('doku.notification_path')`; `hash_equals()` untuk perbandingan. Gagal → **401** `{success:false,message:'Signature tidak valid.'}` + `Log::warning` (payload tidak disimpan).
3. `parseWebhook()` → `event_id` = `doku:{invoice}:{status}:{transaction_date|request_id}`.
4. `DB::transaction`: `firstOrCreate` `PaymentWebhookLog` + `lockForUpdate`; jika `is_processed` → langsung **200** (dedupe retry).
5. Cocokkan `order.invoice_number` → Order. Tidak ada → **404** + warning (DOKU retry; ops melihat log).
6. Verifikasi nominal: `(int) order.amount` harus sama dengan `(int) payment.amount`; beda → catat `raw_response.webhook_amount_mismatch = true`, log critical, **200** (jangan retry storm), jangan tandai lunas.
7. Mapping status:

| Notifikasi DOKU | Order saat ini | Tindakan |
|---|---|---|
| `SUCCESS` | `pending` | `markAsPaid(order, null, 'Pembayaran DOKU sukses (Invoice ...)')` → `payments.status=settlement`, `paid_at`, stok turun |
| `SUCCESS` | `cancelled` / `expired` | `payments.status=settlement` + `paid_at` saja, **tanpa** transisi order → `needsReconciliation()` jadi `true`, log critical, respons 200 |
| `FAILED` | apa pun | tidak mengubah order/payment; klien masih bisa membayar sampai jendela habis; log info |
| status lain | apa pun | log warning, simpan log, 200 |

8. Tandai `PaymentWebhookLog.is_processed = true` + `payment_id` setelah sukses; exception tak terduga → **500** (DOKU retry, log tetap `is_processed=false` agar diproses ulang).
9. Respons selalu JSON `{success:true}` untuk 200.

**Keamanan:** endpoint publik tanpa auth, sehingga signature = satu-satunya kontrol; jangan pernah mempercayai `invoice_number`/`amount` dari body tanpa verifikasi signature; endpoint tidak mengekspos data order.

### 5.6 Rekonsiliasi Harian

**Berkas baru:** `app/Console/Commands/ReconcileDokuPayments.php` — `payments:reconcile-doku {--days=7} {--limit=100}`

- Ambil `payments` dengan `gateway='doku'`, `status='pending'`, `created_at >= now()->subDays(days)`.
- Panggil `GET {base}/orders/v1/status/{invoice_number}` (signature tanpa Digest).
- `transaction.status = SUCCESS` → proses lewat `DokuWebhookService` (jalur yang sama dengan webhook, idempotensi tetap berlaku).
- Ringkas hasil: `diperiksa`, `lunas`, `tetap pending`, `gagal`.
- **Diubah:** [routes/console.php](../routes/console.php) — `Schedule::command('payments:reconcile-doku')->dailyAt('02:00')->withoutOverlapping();`

### 5.7 Visibilitas Admin (Filament)

**Diubah:** [OrderInfolist.php:186](../app/Filament/Resources/Orders/Schemas/OrderInfolist.php#L186) — tambah pada section "Status Payment Gateway":

- `payment.gateway_transaction_id` (copyable) — invoice DOKU;
- `payment_url` (link "Buka halaman pembayaran", `->url(fn ($state) => $state, shouldOpenInNewTab: true)`);
- badge `needs_reconciliation` pada halaman order agar kasus settlement-telat terlihat.

### 5.8 Dokumentasi & Normalisasi Data

- [PUBLIC-API-CONTRACT.md:861](../docs/PUBLIC-API-CONTRACT.md#L861): "soft reservation 15 menit" → **60 menit**.
- [PUBLIC-API-CONTRACT.md:911](../docs/PUBLIC-API-CONTRACT.md#L911): ganti contoh payload QRIS palsu dengan respons DOKU (`gateway`, `payment_url`, `expires_at`, tanpa `qr_string`/`va_number` karangan).
- [README.md:38](../README.md#L38): "Midtrans/Xendit" → "DOKU (DOKU Checkout)"; status integrasi ekspedisi tetap menunggu.
- [AUDIT-VALIDATION.md](../docs/AUDIT-VALIDATION.md) & [AUDIT-UNCONFIRMED-18102026.md](../docs/AUDIT-UNCONFIRMED-18102026.md): tambahkan catatan status "terbuka — DOKU dikonfirmasi 20-10-2026, lihat PLANNING-DOKU-PAYMENT".
- Normalisasi string demo `'midtrans'` → `'doku'` di [OrderManualVerificationSeeder.php](../database/seeders/OrderManualVerificationSeeder.php) dan test terkait (§7).

---

## 6. Perubahan Kontrak API (Dampak ke Frontend)

`POST /api/v1/checkout/orders` — request **tidak berubah** (field `payment_method` tetap). Respons bertambah:

```json
{
  "success": true,
  "message": "Pesanan berhasil dibuat.",
  "data": {
    "order_number": "UJN-20261020-A1B2C3",
    "status": "menunggu_pembayaran",
    "grand_total": 296200,
    "payment_expires_at": "2026-10-20T16:15:00+08:00",
    "payment": {
      "gateway": "doku",
      "method": "qris",
      "reference": "UJN-20261020-A1B2C3",
      "payment_url": "https://sandbox.doku.com/checkout/link/xxxx",
      "instructions": {
        "method": "qris",
        "payment_url": "https://sandbox.doku.com/checkout/link/xxxx",
        "qr_string": null,
        "va_number": null,
        "bank_name": null,
        "instructions_text": "Selesaikan pembayaran melalui halaman pembayaran DOKU.",
        "expires_at": "2026-10-20T16:15:00+08:00"
      }
    }
  }
}
```

- **Wajib** untuk FE: redirect ke `data.payment.payment_url` (bukan lagi menampilkan QR/VA sendiri).
- `data.payment.instructions.qr_string`/`va_number` tetap ada (null) agar FE lama tidak pecah.
- `GET /orders/{orderNumber}` ([OrderDetailResource.php:73](../app/Http/Resources/Api/V1/OrderDetailResource.php#L73)) menambahkan `payment.payment_url` dan `payment.reference` dengan pola yang sama.
- Endpoint baru: `POST /api/v1/payments/webhook/doku` — **bukan** untuk konsumsi frontend, hanya dipanggil DOKU.

---

## 7. Rencana Pengujian

### Test baru (feature, `Http::fake()` + `Http::preventStrayRequests()`)

| Berkas | Kasus |
|---|---|
| `tests/Feature/Payments/DokuCheckoutGatewayTest.php` | signature sesuai spec; payload `payment_method_types` sesuai mapping; `amount` integer == jumlah `line_items` (termasuk saat ada voucher); parsing `payment.url`; exception saat DOKU mengembalikan error; exception saat kredensial kosong |
| `tests/Feature/Payments/DokuWebhookTest.php` | `SUCCESS` + order pending → `paid`, `payment.settlement`, stok turun; notifikasi duplikat → satu transisi (idempotensi); signature salah → 401 & order tidak berubah; invoice tak dikenal → 404; `SUCCESS` setelah order dibatalkan → `settlement` + `needs_reconciliation = true` tanpa membuka order; nominal tidak cocok → tidak lunas; `FAILED` → order tetap pending |
| `tests/Feature/Payments/ReconcileDokuPaymentsCommandTest.php` | payment pending + DOKU `SUCCESS` → lunas; `FAILED`/`NOT_FOUND` → tetap pending; batas `--days` |
| `tests/Feature/Api/V1/PaymentInstructionNeutralTest.php` (diubah) | tetap menjaga regresi S-03: tidak ada `qr_string`/`va_number` karangan, tetapi kini `gateway='doku'` dan `payment_url` berasal dari DOKU |

### Test lama yang harus disesuaikan

| Berkas | Penyesuaian |
|---|---|
| [RegisteredCheckoutApiTest.php](../tests/Feature/Api/V1/RegisteredCheckoutApiTest.php) | `Http::fake` DOKU di `setUp`; ekspektasi `payment_expires_at` 15 → 60 menit; fixture `gateway: 'midtrans'` → `'doku'` |
| [CartAndCheckoutEdgeCasesTest.php](../tests/Feature/Api/V1/CartAndCheckoutEdgeCasesTest.php) | fake DOKU; komentar & perhitungan batas 15 menit → 60 menit |
| [OrderResourceTest.php](../tests/Feature/Filament/OrderResourceTest.php) | fixture & `assertSee('midtrans')` → `'doku'` |
| [OrderReconciliationFlagTest.php](../tests/Feature/Orders/OrderReconciliationFlagTest.php) | fixture `gateway` → `'doku'` |
| [OrderTransitionServiceTest.php](../tests/Feature/Orders/OrderTransitionServiceTest.php), [OrderStrictQaTest.php](../tests/Feature/Orders/OrderStrictQaTest.php) | teks catatan "Midtrans" → "DOKU" |
| [ExpireUnpaidOrdersCommandTest.php](../tests/Feature/Orders/ExpireUnpaidOrdersCommandTest.php) | teks alasan kedaluwarsa 15 → 60 menit |
| [ExpireUnpaidOrders.php](../app/Console/Commands/ExpireUnpaidOrders.php) | pesan alasan & komentar ikut 60 menit |

### Perintah verifikasi

```bash
php artisan test --compact tests/Feature/Payments tests/Feature/Api/V1/RegisteredCheckoutApiTest.php
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan route:list --path=api/v1/payments
```

**Definition of Done:** seluruh suite hijau, Pint bersih, kontrak API + dua `.env.example` diperbarui, tidak ada lagi string `midtrans` selain di dokumen audit historis, dan satu transaksi sandbox DOKU berhasil end-to-end (redirect → bayar → notifikasi → order `paid`).

---

## 8. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Settlement datang setelah order dibatalkan | Uang masuk, order mati | Jendela 60 menit + jalur `SUCCESS`-setelah-batal (§5.5 butir 7) + flag rekonsiliasi + log critical |
| Notifikasi DOKU di-retry | Proses ganda / 500 berulang | `external_event_id` UNIQUE + `is_processed` + `lockForUpdate`; transisi hanya dari `pending` |
| Nominal notifikasi ≠ nominal order | Order salah ditandai lunas | Bandingkan `amount` sebelum transisi; mismatch → flag + log, tidak lunas |
| Panggilan DOKU lambat menahan lock stok | Checkout lain antre | `connectTimeout 3`/`timeout 10`, tanpa retry; volume D2C |
| Kredensial belum diisi / salah | Checkout online gagal | `isConfigured()` → pesan jelas; error DOKU → 502 dengan pesan Indonesia; COD tetap jalan |
| Secret bocor ke log/response | Risiko finansial | Tidak log header/secret; pesan exception generik; `.env` tidak di-commit |
| Perubahan 15→60 menit menaikkan stok tertahan | Stok berputar lebih lambat | Diterima sesuai keputusan pengguna; `orders:expire-unpaid` tetap 1 menit setelah batas |
| FE belum redirect ke `payment_url` | Customer tidak bisa bayar | Kontrak API diperbarui + koordinasi FE sebelum rilis |

---

## 9. Yang Dibutuhkan dari PM/Klien Sebelum Eksekusi

1. **Kredensial sandbox** (`Client-Id`, `Secret-Key`) dan **production** dari DOKU Dashboard → Integration.
2. **URL notifikasi** yang didaftarkan di DOKU Back Office: `https://<domain-api>/api/v1/payments/webhook/doku` (harus HTTPS, tanpa auth).
3. **Daftar metode yang diaktifkan** untuk merchant (VA bank apa saja, QRIS, e-wallet mana, kartu) — menentukan nilai `payment_method_types`.
4. **Kebijakan settlement telat**: refund otomatis, refund manual via admin, atau buka ulang pesanan (default rencana: flag + tindakan manual admin).
5. Konfirmasi bahwa **voucher hangus saat kedaluwarsa** tetap seperti sekarang (TODO lama di `ExpireUnpaidOrders`), karena jendela berubah ke 60 menit.
6. Konfirmasi apakah **SDK resmi** boleh ditambahkan kelak (saat ini rencana: tidak, tanpa dependency baru).

---

## 10. Urutan Eksekusi (Setelah Disetujui)

| Fase | Isi | Berkas utama |
|---|---|---|
| 0 | Konfigurasi & env | `config/doku.php`, `config/app.php` (`frontend_url`), `.env.example`, `.env.production.example` |
| 1 | Kontrak + client DOKU | `PaymentGatewayContract`, `DokuCheckoutGateway`, `AppServiceProvider` |
| 2 | Checkout 60 menit + `payment_url` | `RegisteredCheckoutService`, `CheckoutController`, `OrderDetailResource` |
| 3 | Webhook + idempotensi + late settlement | `routes/api.php`, `PaymentWebhookController`, `DokuWebhookService` |
| 4 | Rekonsiliasi + Filament | `ReconcileDokuPayments`, `routes/console.php`, `OrderInfolist` |
| 5 | Test, docs, normalisasi string | seluruh §7 + [PUBLIC-API-CONTRACT.md](../docs/PUBLIC-API-CONTRACT.md), [README.md](../README.md) |

Setiap fase diakhiri `vendor/bin/pint --dirty --format agent` dan test terdekat; fase 5 diakhiri suite penuh.

---

## 11. Di Luar Cakupan

- Integrasi SNAP DOKU (VA/e-wallet langsung di halaman sendiri, token B2B, RSA keypair).
- Refund otomatis lewat API DOKU (saat ini refund tetap manual di dashboard/admin).
- Notifikasi email/WhatsApp otomatis untuk perubahan status pembayaran.
- Multi-gateway (Midtrans/Xendit sebagai fallback) — YAGNI sampai ada kebutuhan nyata.
- Perubahan UI storefront (redirect `payment_url`) — tanggung jawab tim FE, kontraknya sudah disiapkan di §6.

---

## 12. Referensi

- [DOKU Checkout — supported payment methods (nilai `payment_method_types`)](https://developers.doku.com/accept-payments/doku-checkout/configuration/supported-payment-methods.md)
- [DOKU signature (non-SNAP)](https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-component-from-request-header)
- [DOKU PHP SDK resmi (SNAP)](https://github.com/PTNUSASATUINTIARTHA-DOKU/doku-php-library)
- [Contoh resmi plugin WooCommerce DOKU (payload, signature, notifikasi)](https://github.com/PTNUSASATUINTIARTHA-DOKU/doku-woocommerce-plugin)
- [Postman collection resmi Jokul](https://github.com/PTNUSASATUINTIARTHA-DOKU/doku-postman-collection)

---

## 13. Catatan Implementasi (status akhir)

Diimplementasikan pada branch `sandbox/doku`. Perbedaan kecil dari draf awal:

| Draf | Implementasi | Alasan |
|---|---|---|
| `DokuCheckoutGateway` | `DokuCheckoutClient` (`app/Services/Payments/Doku/`) | Mengikuti penamaan klien HTTP yang sudah dibuat di branch |
| `doku.payment_window_minutes` dari `DOKU_PAYMENT_WINDOW_MINUTES` | `DOKU_PAYMENT_DUE_MINUTES` | Menyesuaikan variabel `.env` yang sudah diisi kredensial DOKU |
| `payment_url` di `raw_response` | `raw_response.payment_url` + `data.payment.payment_url` di API | Tanpa migrasi baru; FE membaca field eksplisit |
| Webhook tanpa `Request-Target` eksplisit | `DOKU_NOTIFICATION_PATH` (default `/api/v1/payments/webhook/doku`) | Komponen signature DOKU wajib sama dengan path URL notifikasi |
| Rekonsiliasi harian | `payments:reconcile-doku` + `Schedule::dailyAt('02:00')` | Sesuai §5.6 |

Berkas utama: `config/doku.php`, `app/Services/Payments/Doku/{DokuSignature,DokuCheckoutClient}.php`,
`app/Services/Payments/DokuWebhookService.php`, `app/Http/Controllers/Api/V1/PaymentWebhookController.php`,
`app/Console/Commands/ReconcileDokuPayments.php`, `app/Exceptions/PaymentGatewayException.php`,
serta test di `tests/Feature/Payments/` + `tests/Support/FakesDokuCheckout.php`.

Verifikasi lokal: `php artisan test --compact` → **492 passed (2.622 assertions)**; `vendor/bin/pint` bersih.
