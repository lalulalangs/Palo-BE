# Panduan Uji Manual Integrasi DOKU Checkout

| Atribut | Nilai |
|---|---|
| Tujuan | Verifikasi manual alur pembayaran DOKU (checkout → halaman bayar → notifikasi → order lunas) tanpa menunggu otomatisasi |
| Lingkungan | Lokal (`php artisan serve`) dengan kredensial **sandbox** DOKU dari `.env` |
| Basis Kode | Branch `sandbox/doku` (setelah implementasi DOKU Checkout) |
| Sumber Kebenaran | [DOKU Backend Integration](https://developers.doku.com/accept-payments/doku-checkout/integration-guide/backend-integration) + [Signature non-SNAP](https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-component-from-request-header) + [Check Status API](https://developers.doku.com/get-started-with-doku-api/check-status-api/non-snap) |
| Dokumen Terkait | [PLANNING-DOKU-PAYMENT.md](./PLANNING-DOKU-PAYMENT.md), [PUBLIC-API-CONTRACT.md](./PUBLIC-API-CONTRACT.md) §12 |
| Status | Panduan manual — tidak mengubah kode |

---

## 0. Ringkasan Alur yang Diuji

```
Postman                         Backend                           DOKU
   |  POST /checkout/orders  --> | validasi + kunci stok 60 menit    |
   |                             | POST /checkout/v1/payment  -----> |
   |                             | <----- 200 {payment.url}          |
   | <-- 201 {payment_url} ----- | Payment(gateway=doku) disimpan    |
   |                             |                                   |
   |  (buka payment_url / bayar di sandbox DOKU) -------------------> |
   |                             | <-- POST /payments/webhook/doku --|
   |                             | verifikasi signature + idempotensi|
   |                             | order => paid, stok turun         |
   |  Admin /admin → Orders      | status lunas + link pembayaran    |
```

Ada dua jalur:

- **Jalur A (cepat, tanpa tunnel):** notifikasi DOKU disimulasikan lewat Postman, tetapi **signature tetap dihitung asli** dengan `DOKU_SECRET_KEY` milikmu. Ini menguji seluruh logika backend.
- **Jalur B (end-to-end nyata):** pasang tunnel publik, daftarkan URL notifikasi di DOKU Back Office sandbox, lalu bayar lewat halaman DOKU + simulator. Ini menguji kompatibilitas kredensial & signature di sisi DOKU.

---

## 1. Persiapan

```bash
# 1. Siapkan database + data contoh (admin, kategori, produk, SKU stok 25/30)
php artisan migrate --seed

# 2. Jalankan server
php artisan serve          # http://localhost:8000
```

- **Admin panel:** `http://localhost:8000/admin` → login dengan `ADMIN_EMAIL` / `ADMIN_PASSWORD` dari `.env`.
  Jika gagal login: isi `ADMIN_PASSWORD` di `.env`, lalu `php artisan db:seed --class=RoleAndAdminUserSeeder`.
- **Pastikan kredensial DOKU terisi** di `.env` (`DOKU_ENV=sandbox`, `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY`, `DOKU_BASE_URL`), lalu `php artisan config:clear` bila baru diubah.

**Environment Postman** (jangan di-commit / dibagikan):

| Variable | Nilai |
|---|---|
| `base_url` | `http://127.0.0.1:8000/api/v1` |
| `doku_client_id` | isi `DOKU_CLIENT_ID` |
| `doku_secret_key` | isi `DOKU_SECRET_KEY` |
| `doku_notification_path` | `/api/v1/payments/webhook/doku` |
| `token` | diisi otomatis oleh request login/register |
| `order_number`, `amount` | diisi otomatis oleh request checkout |

---

## 2. Cek UI Admin Tanpa Transaksi

Berguna untuk memastikan tampilan admin berfungsi sebelum menyentuh DOKU.

```bash
php artisan db:seed --class=OrderManualVerificationSeeder
```

Buka **Admin → Orders**, pilih salah satu order, lalu pada section **“Status Payment Gateway”** pastikan tampil:

- [ ] `Gateway Pembayaran` = `doku`
- [ ] `Referensi Gateway (Invoice DOKU)`
- [ ] `Halaman Pembayaran DOKU` berupa link yang bisa diklik
- [ ] `Rekonsiliasi` = `-` (order normal)

---

## 3. Jalur A — Buat Order Online via Postman

### 3.1 Register customer

`POST {{base_url}}/auth/register`

```json
{
  "name": "QA Payment",
  "email": "qa.payment@example.test",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "phone": "081234567890"
}
```

Tab **Tests**:

```js
pm.test("201 created", () => pm.response.to.have.status(201));
pm.environment.set("token", pm.response.json().data.token);
```

> Sudah punya akun? Pakai `POST {{base_url}}/auth/login` dengan body `{"email":"...","password":"..."}`.
> Ganti email setiap kali mengulang dari nol (`unique:users,email`).

### 3.2 Ambil `sku_id`

`GET {{base_url}}/products/palo-rinjani-classic-hoodie`

Ambil `data.variants[0].skus[0].id` (SKU seed memiliki stok 25/30).

### 3.3 Checkout

`POST {{base_url}}/checkout/orders`

Header: `Authorization: Bearer {{token}}`, `Content-Type: application/json`

```json
{
  "shipping_address": {
    "recipient_name": "QA Payment",
    "phone": "081234567890",
    "street": "Jl. Pariwisata Senaru No. 12",
    "city": "Lombok Utara",
    "province": "Nusa Tenggara Barat",
    "postal_code": "83354"
  },
  "items": [{ "sku_id": 1, "quantity": 1 }],
  "shipping_courier": "JNE",
  "shipping_service": "REG",
  "payment_method": "qris"
}
```

Tab **Tests**:

```js
const body = pm.response.json();
pm.test("201 + payment_url", () => {
  pm.response.to.have.status(201);
  pm.expect(body.data.payment.payment_url).to.be.a("string");
});
pm.environment.set("order_number", body.data.order_number);
pm.environment.set("amount", String(body.data.grand_total));
```

**Ekspektasi:**

| Field | Nilai |
|---|---|
| HTTP status | `201` |
| `data.payment.gateway` | `doku` |
| `data.payment.payment_url` | `https://sandbox.doku.com/checkout/link/...` |
| `data.payment.reference` | sama dengan `data.order_number` |
| `data.payment.instructions.qr_string` / `va_number` | `null` (tidak ada QR/VA karangan) |
| `data.payment_expires_at` | order dibuat + 60 menit |

Jika respons `502`/`503`, **jangan lanjut** — lihat §7.

### 3.4 (Opsional) Buka halaman pembayaran

Buka `payment_url` di browser untuk memastikan halaman DOKU tampil. Bila halaman menolak “no payment method”, set `DOKU_RESTRICT_METHOD_TYPES=false` di `.env`, `php artisan config:clear`, lalu ulangi checkout dengan order baru.

---

## 4. Jalur A — Simulasi Notifikasi DOKU

Request baru: `POST {{base_url}}/payments/webhook/doku` (tanpa auth).

Body → tab **Body → raw → JSON**:

```json
{
  "service": { "id": "QRIS" },
  "acquirer": { "id": "DOKU" },
  "channel": { "id": "QRIS_DOKU" },
  "transaction": {
    "status": "SUCCESS",
    "date": "2026-10-20T09:00:00Z",
    "original_request_id": "manual-test-001"
  },
  "order": {
    "invoice_number": "{{order_number}}",
    "amount": 0
  }
}
```

- `order.invoice_number` = `{{order_number}}` dari langkah 3.3.
- `order.amount` = total order (integer; isi manual atau `{{amount}}`).
- `transaction.date` bebas; **ganti nilainya** bila ingin mengirim event baru untuk invoice yang sama.

Tab **Pre-request Script** (menghitung signature HMAC-SHA256 asli):

```js
const clientId = pm.environment.get("doku_client_id");
const secret   = pm.environment.get("doku_secret_key");
const target   = pm.environment.get("doku_notification_path");

const requestId = pm.variables.replaceIn("{{$guid}}");
const timestamp = new Date().toISOString().replace(/\.\d{3}Z$/, "Z");
const body = pm.request.body.raw;

// Digest = base64(SHA256(body)); hanya untuk POST.
const digest = CryptoJS.SHA256(body).toString(CryptoJS.enc.Base64);

const component = [
  "Client-Id:" + clientId,
  "Request-Id:" + requestId,
  "Request-Timestamp:" + timestamp,
  "Request-Target:" + target,
  "Digest:" + digest
].join("\n");

const signature = "HMACSHA256=" + CryptoJS.HmacSHA256(component, secret).toString(CryptoJS.enc.Base64);

pm.request.headers.upsert({ key: "Content-Type", value: "application/json" });
pm.request.headers.upsert({ key: "Client-Id", value: clientId });
pm.request.headers.upsert({ key: "Request-Id", value: requestId });
pm.request.headers.upsert({ key: "Request-Timestamp", value: timestamp });
pm.request.headers.upsert({ key: "Signature", value: signature });
```

> Jangan menambah/mengubah body setelah pre-request script berjalan — `Digest` dihitung dari body mentah yang dikirim.

### 4.1 Matriks hasil yang diharapkan

| Aksi | HTTP | Body respons |
|---|---|---|
| Kirim pertama | `200` | `{"success":true,"message":"Notifikasi diterima."}` |
| Kirim ulang persis sama | `200` | `"Notifikasi sudah diproses sebelumnya."` |
| Ubah `Signature` menjadi ngawur | `401` | `{"success":false,"message":"Signature tidak valid."}` |
| Hapus `transaction` / `order.invoice_number` | `400` | pesan payload tidak valid |
| `invoice_number` tidak dikenal | `404` | pesan pesanan tidak ditemukan |

Catatan: `external_event_id` = `doku:{invoice}:{status}:{transaction.date}`. Karena itu kiriman ulang identik = duplikat (idempoten), sedangkan tanggal berbeda = event baru.

---

## 5. Verifikasi di Dashboard Admin

Buka **/admin → Orders → order terkait**.

**Status pembayaran**

- [ ] Status pesanan: **Dibayar**
- [ ] Status Transaksi Gateway: **settlement**
- [ ] Waktu Pelunasan terisi
- [ ] Referensi Gateway = `order_number`
- [ ] Halaman Pembayaran DOKU tetap tampil
- [ ] Rekonsiliasi: `-`

**Riwayat & stok**

- [ ] Riwayat status berisi “Pembayaran DOKU sukses (Invoice …)”
- [ ] **Products** → stok SKU berkurang sesuai kuantitas
- [ ] **Stock Movements** → ada pergerakan tipe penjualan, referensi order, qty negatif

**Audit trail (opsional, lewat CLI)**

```bash
php artisan tinker
```

```php
App\Models\PaymentWebhookLog::latest('id')->first()->only(['external_event_id', 'event_type', 'is_processed']);
App\Models\Order::where('order_number', 'ISI_ORDER_NUMBER')->first()->payment->raw_response;
```

```bash
tail -f storage/logs/laravel.log | grep -i "payment\."
```

---

## 6. Skenario Wajib Lain

| Skenario | Langkah | Ekspektasi |
|---|---|---|
| **Nominal tidak cocok** | Buat order baru → kirim notifikasi `SUCCESS` dengan `amount` = total + 1000 | `200`; order tetap *Menunggu Pembayaran*; log `payment.apply.amount_mismatch`; `payment.raw_response.amount_mismatch` terisi; tidak ada perubahan stok |
| **Pulih setelah mismatch** | Kirim ulang notifikasi `SUCCESS` dengan nominal benar dan `transaction.date` berbeda | Order menjadi *Dibayar* |
| **Settlement setelah dibatalkan** | Buat order baru → di admin jalankan aksi **Batalkan** → kirim notifikasi `SUCCESS` | Payment `settlement` + `paid_at`; order tetap *Dibatalkan*; badge **Perlu rekonsiliasi** muncul; stok tidak berubah |
| **Checkout gagal aman** | Set `DOKU_SECRET_KEY=salah` + `config:clear` → checkout | `502 payment_gateway_error`; jumlah order **tidak bertambah** (rollback total) |
| **Kredensial kosong** | Set `DOKU_CLIENT_ID=` → checkout | `503 payment_unavailable`; tidak ada order baru |
| **Tidak ada QR/VA karangan** | Cek `payments.raw_response` order online | `qr_string` dan `va_number` = `null`; `payment_url` terisi |
| **Status non-final** | Kirim notifikasi `FAILED` | `200`; order tetap *Menunggu Pembayaran*; payment tetap `pending` |

---

## 7. Troubleshooting

| Gejala | Penyebab paling mungkin | Perbaikan |
|---|---|---|
| Webhook `401` | `doku_notification_path` di Postman berbeda dari `DOKU_NOTIFICATION_PATH`; secret salah/ada spasi; `Client-Id` beda | Samakan nilainya, cek `.env` tidak ter-quote aneh |
| Webhook `404` | `invoice_number` salah atau order tidak ada di database yang dipakai | Salin ulang dari respons checkout |
| Webhook `400` | Body kehilangan `order.invoice_number` atau `transaction.status` | Perbaiki payload |
| Checkout `502` | DOKU menolak (kanal belum aktif, `payment_method_types` tidak dikenal, kredensial salah) | Lihat `payment.doku.checkout_rejected` di log (`errors`); coba `payment_method=virtual_account`; set `DOKU_RESTRICT_METHOD_TYPES=false` |
| Checkout `503` | `DOKU_CLIENT_ID`/`DOKU_SECRET_KEY` kosong | Isi `.env` + `php artisan config:clear` |
| Halaman DOKU error | Produk DOKU Checkout belum aktif di akun sandbox | Konfirmasi aktivasi ke DOKU |
| Order kedaluwarsa saat dites lama | Jendela bayar 60 menit terlewat | Buat order baru |
| `429` | Melebihi throttle webhook 120/menit | Tunggu, ulangi |

---

## 8. Jalur B — End-to-End DOKU Sandbox (Opsional, Butuh URL Publik)

1. Tunnel lokal:

   ```bash
   cloudflared tunnel --url http://localhost:8000
   # atau: ngrok http 8000
   ```

2. Di **DOKU Back Office (sandbox)** → Integration/Notification, daftarkan:
   `https://<host-tunnel>/api/v1/payments/webhook/doku`
   Harus **persis** sama dengan `DOKU_NOTIFICATION_PATH` (path inilah komponen `Request-Target` signature).

3. Buat order via Postman (§3), buka `payment_url`, lalu bayar memakai simulator sandbox DOKU:
   [Simulate Payment and Notification](https://developers.doku.com/accept-payments/doku-checkout/integration-guide/simulate-payment-and-notification).

4. Amati:
   - Admin berubah **Dibayar** tanpa notifikasi manual;
   - Log `payment.webhook` **tidak** berisi `invalid_signature`;
   - `payment_webhook_logs.is_processed = true`.

### 8.1 Uji Rekonsiliasi

```bash
php artisan payments:reconcile-doku --days=1
```

| Kondisi | Output yang diharapkan |
|---|---|
| Order belum dibayar | `1 diperiksa, 0 jadi lunas, 1 belum final, 0 gagal` |
| Sudah dibayar tetapi notifikasi diblokir (tunnel dimatikan) | `1 jadi lunas`, `raw_response.settled_via = reconciliation` |
| Kredensial kosong | pesan “Kredensial DOKU belum dikonfigurasi” + exit code 1 |

Catatan DOKU: Check Status sebaiknya dipanggil **≥60 detik** setelah pembayaran selesai.

---

## 9. Checklist Ringkas

- [ ] Admin bisa menampilkan order dengan gateway `doku` + `payment_url`
- [ ] Checkout mengembalikan `payment_url`, `gateway=doku`, tanpa QR/VA karangan
- [ ] Jendela bayar 60 menit (`payment_expires_at`, `stock_locked_until`)
- [ ] Notifikasi valid → order `paid`, payment `settlement`, stok turun, riwayat tercatat
- [ ] Notifikasi duplikat → satu transisi, respons 200
- [ ] Signature salah → 401, tidak ada perubahan data
- [ ] Nominal salah → tidak lunas + flag
- [ ] Settlement setelah batal → lunas + badge rekonsiliasi
- [ ] Checkout gagal → 502 + rollback (tidak ada order menggantung)
- [ ] Rekonsiliasi manual berjalan (lunas / belum final)
- [ ] (Jalur B) Notifikasi asli DOKU diterima & lolos signature

---

## 10. Referensi Implementasi

| Berkas | Peran |
|---|---|
| [config/doku.php](../config/doku.php) | Kredensial, base URL, jendela bayar, path notifikasi |
| [DokuSignature.php](../app/Services/Payments/Doku/DokuSignature.php) | Signature POST/GET + verifikasi notifikasi |
| [DokuCheckoutClient.php](../app/Services/Payments/Doku/DokuCheckoutClient.php) | Pembuatan transaksi, parsing respons, Check Status |
| [DokuWebhookService.php](../app/Services/Payments/DokuWebhookService.php) | Verifikasi, idempotensi, transisi status, rekonsiliasi |
| [PaymentWebhookController.php](../app/Http/Controllers/Api/V1/PaymentWebhookController.php) | Endpoint `POST /api/v1/payments/webhook/doku` |
| [ReconcileDokuPayments.php](../app/Console/Commands/ReconcileDokuPayments.php) | Command `payments:reconcile-doku` |
| [tests/Feature/Payments](../tests/Feature/Payments) | Test otomatis (fake HTTP) sebagai pembanding hasil manual |
| [PUBLIC-API-CONTRACT.md](./PUBLIC-API-CONTRACT.md) §12 | Kontrak respons checkout untuk frontend |

> Berkas ini berada di `docs/` yang di-ignore git. Agar ikut ter-commit: `git add -f docs/MANUAL-TEST-DOKU.md`.
