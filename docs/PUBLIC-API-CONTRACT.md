# Dokumentasi & Kontrak Public API — Palo Rinjani (v1)

Dokumen ini adalah referensi kontrak resmi **Public REST API (v1)** yang dikonsumsi oleh aplikasi frontend (Next.js Storefront / Mobile App).

File master tersimpan di: [docs/PUBLIC-API-CONTRACT.md](file:///home/lalulalang/Documents/palorinjani/docs/PUBLIC-API-CONTRACT.md)

---

## 1. Konvensi Umum API

### 1.1 Base URL & Environment
* **Development:** `http://localhost:8000/api/v1` (atau port yang dikonfigurasi pada backend)
* **Production:** `https://api.palorinjani.com/api/v1` (sesuai domain server production)

### 1.2 Header Standar
* Setiap request **WAJIB** menyertakan header:
  ```http
  Accept: application/json
  ```
* Untuk request dengan body (`POST`, `PUT`, `PATCH`), sertakan header:
  ```http
  Content-Type: application/json
  ```
* Untuk endpoint yang membutuhkan autentikasi pelanggan:
  ```http
  Authorization: Bearer <token>
  ```

### 1.3 Format Data & Nilai
* **Mata Uang:** Nilai numerik harga selalu berupa **integer/float rupiah** (misal `149000`), didampingi field `formatted_*` untuk kemudahan render tampilan (misal `"Rp 149.000"`).
* **Waktu/Tanggal:** Format standar **ISO-8601 UTC** (contoh: `2026-10-11T07:03:00.000000Z`).
* **Bahasa Pesan:** Semua pesan respon status dan validasi disajikan dalam **Bahasa Indonesia**.

### 1.4 Format Standar Error
Seluruh respon error (HTTP 4xx & 5xx) memiliki struktur seragam dengan root field `"success": false`.

Jika terjadi kegagalan validasi (HTTP 422), format respon:
```json
{
  "success": false,
  "message": "Pesan ringkas kegagalan validasi (bersih tanpa embel-embel teks bawaan)",
  "errors": {
    "field_name": [
      "Pesan error spesifik untuk field ini"
    ]
  }
}
```

Jika terjadi error autentikasi / hak akses / tidak ditemukan / rate limit / konflik / server error (HTTP 401, 403, 404, 409, 429, 500):
```json
{
  "success": false,
  "message": "Pesan penjelasan penyebab kegagalan"
}
```

---

## 2. Autentikasi & Akun Pelanggan (Customer Auth)

### 2.1 Pendaftaran Akun Baru
Mendaftarkan akun buyer baru dan langsung menerbitkan access token Bearer.

* **Endpoint:** `POST /auth/register`
* **Auth:** Tidak (Publik)
* **Rate Limit:** 10 percobaan / menit
* **Headers:** `Accept: application/json`, `Content-Type: application/json`

#### Request Body
```json
{
  "name": "Budi Santoso",
  "email": "budi.santoso@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "phone": "081234567890"
}
```
* **Aturan Validasi:**
  * `name`: Wajib, string, maks 120 karakter.
  * `email`: Wajib, format email valid, unik di tabel users, otomatis dinormalisasi (`lowercase` + `trim`).
  * `password`: Wajib, minimal 8 karakter, maks 255 karakter, harus cocok dengan `password_confirmation`.
  * `password_confirmation`: Wajib.
  * `phone`: Opsional (nullable), maks 20 karakter.

#### Respon Berhasil (`201 Created`)
```json
{
  "success": true,
  "message": "Pendaftaran akun berhasil.",
  "data": {
    "token": "1|abc123plaintexttokensanctum...",
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi.santoso@example.com",
      "phone": "081234567890",
      "email_verified_at": null,
      "created_at": "2026-10-11T07:00:00.000000Z"
    }
  }
}
```

#### Respon Gagal (`422 Unprocessable Content`)
```json
{
  "message": "Alamat surel sudah terdaftar.",
  "errors": {
    "email": [
      "Alamat surel sudah terdaftar."
    ]
  }
}
```

---

### 2.2 Masuk (Login)
Otentikasi pembeli menggunakan email dan kata sandi.

* **Endpoint:** `POST /auth/login`
* **Auth:** Tidak (Publik)
* **Rate Limit:** 10 percobaan / menit
* **Headers:** `Accept: application/json`, `Content-Type: application/json`

#### Request Body
```json
{
  "email": "budi.santoso@example.com",
  "password": "Password123!",
  "remember": true
}
```

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Berhasil masuk.",
  "data": {
    "token": "2|xyz456plaintexttokensanctum...",
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi.santoso@example.com",
      "phone": "081234567890",
      "email_verified_at": null,
      "created_at": "2026-10-11T07:00:00.000000Z"
    }
  }
}
```

#### Respon Gagal (`401 Unauthorized`)
```json
{
  "success": false,
  "message": "Alamat surel atau kata sandi tidak cocok.",
  "errors": {
    "email": [
      "Kredensial yang diberikan tidak cocok dengan data kami."
    ]
  }
}
```

---

### 2.3 Profil Pengguna yang Sedang Login
Mengambil data ringkas akun pemilik sesi saat ini.

* **Endpoint:** `GET /auth/me`
* **Auth:** Ya (`Authorization: Bearer <token>`)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Profil berhasil diambil.",
  "data": {
    "id": 1,
    "name": "Budi Santoso",
    "email": "budi.santoso@example.com",
    "phone": "081234567890",
    "email_verified_at": null,
    "created_at": "2026-10-11T07:00:00.000000Z"
  }
}
```

#### Respon Gagal (`401 Unauthorized`)
```json
{
  "message": "Unauthenticated."
}
```

---

### 2.4 Keluar (Logout)
Mencabut token sesi yang sedang digunakan.

* **Endpoint:** `POST /auth/logout`
* **Auth:** Ya (`Authorization: Bearer <token>`)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Berhasil keluar."
}
```

---

## 3. Katalog Produk

### 3.1 Daftar Produk (List Products)
Mengambil daftar produk aktif dengan filter, pencarian, pengurutan, dan paginasi.

* **Endpoint:** `GET /products`
* **Auth:** Tidak (Publik)

#### Parameter Query (Opsional)
| Parameter | Tipe | Contoh | Keterangan |
|---|---|---|---|
| `search` | string | `tenda` | Pencarian substring pada nama atau deskripsi produk |
| `category` | string | `apparel` | Slug kategori (termasuk sub-kategori turunannya) |
| `collection` | string | `senaru-series` | Slug koleksi kurasi |
| `min_price` | number | `100000` | Filter batas harga minimum (Rupiah) |
| `max_price` | number | `500000` | Filter batas harga maksimum (Rupiah) |
| `in_stock` | boolean/string | `true` atau `1` | Hanya tampilkan produk dengan total stok varian > 0 |
| `sort` | string | `price_asc` | Nilai yang didukung: `price_asc`, `price_desc`, `latest`, `popular` |
| `per_page` | integer | `24` | Jumlah item per halaman (1–48, default: 24) |
| `page` | integer | `1` | Nomor halaman paginasi |

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar produk berhasil diambil",
  "data": [
    {
      "id": 1,
      "name": "Kaos Dryfit Senaru Summit",
      "slug": "kaos-dryfit-senaru-summit",
      "base_price": 149000,
      "formatted_base_price": "Rp 149.000",
      "thumbnail_url": "http://localhost:8000/storage/products/thumbnails/kaos-senaru.webp",
      "category": {
        "id": 1,
        "name": "Apparel",
        "slug": "apparel"
      },
      "is_featured": true,
      "is_out_of_stock": false,
      "collections": [
        {
          "id": 1,
          "name": "Senaru Expedition Series",
          "slug": "senaru-expedition-series"
        }
      ]
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 24,
    "total": 35,
    "has_more": true
  }
}
```

---

### 3.2 Detail Produk (Product Detail)
Mengambil detail lengkap produk berdasarkan slug (termasuk galeri foto, varian, dan SKU).

* **Endpoint:** `GET /products/{slug}`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Detail produk berhasil diambil",
  "data": {
    "id": 1,
    "name": "Kaos Dryfit Senaru Summit",
    "slug": "kaos-dryfit-senaru-summit",
    "description": "Kaos cepat kering dengan ventilasi optimal untuk pendakian jalur Senaru.",
    "base_price": 149000,
    "formatted_base_price": "Rp 149.000",
    "is_featured": true,
    "total_stock": 25,
    "is_out_of_stock": false,
    "category": {
      "id": 1,
      "name": "Apparel",
      "slug": "apparel",
      "parent": null
    },
    "collections": [
      {
        "id": 1,
        "name": "Senaru Expedition Series",
        "slug": "senaru-expedition-series"
      }
    ],
    "media": [
      {
        "id": 1,
        "url": "http://localhost:8000/storage/products/gallery/kaos-1.webp",
        "type": "image",
        "is_thumbnail": true,
        "sort_order": 0
      },
      {
        "id": 2,
        "url": "http://localhost:8000/storage/products/gallery/kaos-2.webp",
        "type": "image",
        "is_thumbnail": false,
        "sort_order": 1
      }
    ],
    "variants": [
      {
        "id": 1,
        "attributes": {
          "color": "Hitam",
          "size": "M"
        },
        "skus": [
          {
            "id": 1,
            "sku_code": "KDS-HIT-M",
            "price": 149000,
            "formatted_price": "Rp 149.000",
            "stock": 10,
            "is_active": true
          }
        ]
      },
      {
        "id": 2,
        "attributes": {
          "color": "Hitam",
          "size": "L"
        },
        "skus": [
          {
            "id": 2,
            "sku_code": "KDS-HIT-L",
            "price": 149000,
            "formatted_price": "Rp 149.000",
            "stock": 15,
            "is_active": true
          }
        ]
      }
    ]
  }
}
```

#### Respon Produk Tidak Ditemukan (`404 Not Found`)
```json
{
  "success": false,
  "message": "Produk tidak ditemukan atau tidak aktif",
  "data": null
}
```

---

## 4. Kategori Produk

### 4.1 Hierarki Kategori (Nested Categories)
Mengambil daftar kategori root beserta sub-kategori turunan dan jumlah produk aktif.

* **Endpoint:** `GET /categories`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar kategori berhasil diambil",
  "data": [
    {
      "id": 1,
      "name": "Apparel",
      "slug": "apparel",
      "description": "Pakaian penjelajahan dan outdoor",
      "banner_url": null,
      "icon_url": null,
      "products_count": 12,
      "children": [
        {
          "id": 2,
          "name": "T-Shirt & Jersey",
          "slug": "t-shirt-jersey",
          "description": null,
          "banner_url": null,
          "icon_url": null,
          "products_count": 8,
          "children": []
        }
      ]
    }
  ]
}
```

---

## 5. Koleksi / Series (Curated Collections)

### 5.1 Daftar Koleksi
Mengambil seluruh koleksi produk yang aktif.

* **Endpoint:** `GET /collections`
* **Auth:** Tidak (Publik)

#### Parameter Query (Opsional)
| Parameter | Tipe | Contoh | Keterangan |
|---|---|---|---|
| `featured` | boolean/string | `true` atau `1` | Filter hanya koleksi yang disematkan (is_featured = true) |

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar koleksi berhasil diambil",
  "data": [
    {
      "id": 1,
      "name": "Senaru Expedition Series",
      "slug": "senaru-expedition-series",
      "tagline": "Koleksi Tangguh Penjelajahan Gunung Rinjani",
      "banner_image_url": "http://localhost:8000/storage/collections/senaru-banner.webp",
      "is_featured": true,
      "sort_order": 1,
      "products_count": 6
    }
  ]
}
```

---

### 5.2 Detail Koleksi & Produk Terkurasi
Mengambil data koleksi dan daftar produk yang dikurasi di dalamnya dengan paginasi.

* **Endpoint:** `GET /collections/{slug}`
* **Auth:** Tidak (Publik)

#### Parameter Query (Opsional)
| Parameter | Tipe | Default | Keterangan |
|---|---|---|---|
| `per_page` | integer | `12` | Jumlah produk per halaman (maks 100) |
| `page` | integer | `1` | Nomor halaman paginasi |

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Detail koleksi berhasil diambil",
  "data": {
    "id": 1,
    "name": "Senaru Expedition Series",
    "slug": "senaru-expedition-series",
    "tagline": "Koleksi Tangguh Penjelajahan Gunung Rinjani",
    "description": "Perlengkapan esensial pendakian dengan material khusus tahan cuaca.",
    "banner_image_url": "http://localhost:8000/storage/collections/senaru-banner.webp",
    "is_featured": true,
    "products_count": 6,
    "products": {
      "data": [
        {
          "id": 1,
          "name": "Kaos Dryfit Senaru Summit",
          "slug": "kaos-dryfit-senaru-summit",
          "base_price": 149000,
          "formatted_base_price": "Rp 149.000",
          "thumbnail_url": "http://localhost:8000/storage/products/thumbnails/kaos-senaru.webp",
          "is_featured": true,
          "is_out_of_stock": false
        }
      ],
      "pagination": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 12,
        "total": 6,
        "has_more": false
      }
    }
  }
}
```

---

## 6. Banner Promosi

### 6.1 Daftar Banner Aktif
Mengambil banner promosi aktif yang diurutkan berdasarkan `sort_order`.

* **Endpoint:** `GET /banners`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar banner berhasil diambil",
  "data": [
    {
      "id": 1,
      "title": "Promo Spesial Pendakian Rinjani",
      "image_url": "http://localhost:8000/storage/banners/promo-musim-kemarau.webp",
      "link_url": "/koleksi/senaru-expedition-series",
      "sort_order": 1,
      "is_active": true
    }
  ]
}
```

---

## 7. Hero Carousel Slider

### 7.1 Daftar Hero Slider Aktif
Mengambil slide carousel utama untuk landing page beranda.

* **Endpoint:** `GET /hero-sliders`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar hero slider berhasil diambil",
  "data": [
    {
      "id": 1,
      "series_tag": "Rinjani 2026",
      "location_tag": "Senaru, Lombok Utara",
      "badge": "Terbaru",
      "title": "Jelajahi Puncak Rinjani Bersama Palo",
      "description": "Perlengkapan ekspedisi berkualitas tinggi teruji di jalur ekstrem.",
      "images": {
        "desktop_url": "http://localhost:8000/storage/heroes/desktop-1.webp",
        "mobile_url": "http://localhost:8000/storage/heroes/mobile-1.webp",
        "alt_text": "Pemandangan Jalur Senaru Rinjani"
      },
      "actions": {
        "primary": {
          "label": "Belanja Sekarang",
          "url": "/produk"
        },
        "secondary": {
          "label": "Lihat Koleksi",
          "url": "/koleksi"
        }
      },
      "order": 1
    }
  ]
}
```

---

### 7.2 Detail Hero Slider
* **Endpoint:** `GET /hero-sliders/{id}`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Detail hero slider berhasil diambil",
  "data": {
    "id": 1,
    "series_tag": "Rinjani 2026",
    "location_tag": "Senaru, Lombok Utara",
    "badge": "Terbaru",
    "title": "Jelajahi Puncak Rinjani Bersama Palo",
    "description": "Perlengkapan ekspedisi berkualitas tinggi teruji di jalur ekstrem.",
    "images": {
      "desktop_url": "http://localhost:8000/storage/heroes/desktop-1.webp",
      "mobile_url": "http://localhost:8000/storage/heroes/mobile-1.webp",
      "alt_text": "Pemandangan Jalur Senaru Rinjani"
    },
    "actions": {
      "primary": {
        "label": "Belanja Sekarang",
        "url": "/produk"
      },
      "secondary": {
        "label": "Lihat Koleksi",
        "url": "/koleksi"
      }
    },
    "order": 1
  }
}
```

---

## 8. Brand & Pengaturan Toko

### 8.1 Informasi Brand, Kontak CS, dan Alamat Gerai
Mengambil pengaturan dinamis brand, link media sosial resmi, nomor WhatsApp CS, serta alamat gerai offline.

* **Endpoint:** `GET /brand`
* **Auth:** Tidak (Publik)

#### Respon Berhasil (`200 OK`)
```json
{
  "data": {
    "brand_name": "Palo Mountain Goods",
    "tagline": "Merchandise Penjelajahan Senaru, Rinjani",
    "about": "Palo Mountain Goods menyediakan produk penjelajahan alam asli Lombok.",
    "logo_url": null,
    "email": "cs@palorinjani.com",
    "instagram_url": "https://instagram.com/palorinjani_official",
    "instagram_handle": "@palorinjani",
    "facebook_url": "https://facebook.com/palorinjani",
    "tiktok_url": null,
    "whatsapp_number": "081234567890",
    "whatsapp_cs": "081234567890",
    "whatsapp": "081234567890",
    "whatsapp_default_message": "Halo Admin Palo Mountain Goods, saya ingin bertanya seputar produk...",
    "store_name": "Palo Mountain Goods Gerai Senaru",
    "store_address": "Jl. Pariwisata Senaru, Bayan, Lombok Utara, Nusa Tenggara Barat",
    "store_city": "Lombok Utara",
    "store_maps_url": null,
    "store_coordinates": null,
    "store_opening_hours": "Senin – Minggu: 08.00 – 21.00 WITA",
    "site_url": null
  }
}
```

---

## 9. Keranjang Belanja (Cart Management)

Modul keranjang mendukung pengunjung tanpa akun (Guest via cookie sesi / `guest_token`) maupun pembeli terdaftar (Registered User via Bearer Token).

### 9.1 Lihat Keranjang Aktif
* **Endpoint:** `GET /cart`
* **Auth:** Opsional (Jika login kirim Bearer token; jika guest kirim cookie `guest_cart_id` atau header `X-Guest-Token`).

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "sku_id": 1,
        "product_id": 1,
        "product_name": "Kaos Dryfit Senaru Summit",
        "product_slug": "kaos-dryfit-senaru-summit",
        "variant_name": "Hitam / M",
        "sku_code": "KDS-HIT-M",
        "unit_price": 149000,
        "unit_price_display": "Rp 149.000",
        "quantity": 2,
        "subtotal": 298000,
        "subtotal_display": "Rp 298.000",
        "available_stock": 10,
        "is_out_of_stock": false,
        "image": "http://localhost:8000/storage/products/kaos.webp"
      }
    ],
    "summary": {
      "item_count": 1,
      "total_quantity": 2,
      "subtotal": 298000,
      "subtotal_display": "Rp 298.000"
    },
    "guest_token": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
  }
}
```

### 9.2 Tambah Item ke Keranjang
* **Endpoint:** `POST /cart/items`
* **Auth:** Opsional

#### Request Body
```json
{
  "sku_id": 1,
  "quantity": 2
}
```

#### Respon Berhasil (`200 OK`)
Mengembalikan struktur data keranjang yang telah diperbarui. Jika stok tidak mencukupi, server mengembalikan status `422 Unprocessable Content`.

### 9.3 Perbarui Jumlah Item Keranjang
* **Endpoint:** `PATCH /cart/items/{skuId}`
* **Auth:** Opsional

#### Request Body
```json
{
  "quantity": 3
}
```
*(Catatan: jika `quantity` bernilai 0, item otomatis dihapus dari keranjang).*

### 9.4 Hapus Item dari Keranjang
* **Endpoint:** `DELETE /cart/items/{skuId}`
* **Auth:** Opsional

### 9.5 Penggabungan Keranjang Guest ke Akun User (Otomatis, Tanpa Endpoint)
Tidak ada endpoint merge terpisah. Keranjang tamu digabungkan **otomatis** di dalam `POST /auth/login` dan `POST /auth/register` bila request membawa `guest_token` di body, header `X-Guest-Token`, atau cookie `guest_cart_id`. Server memutar token tamu dan mengirimkan token baru lewat cookie `guest_cart_id`.

> `POST /cart/merge` dihapus 25-10-2026: tidak punya konsumen, dan perilakunya sudah tercakup oleh login/register (`AuthController::mergeGuestCartIfPresent()`).

---

## 10. Jalur A: Guest Checkout via WhatsApp CS (PRD §3.11)

Jalur cepat untuk pengunjung tanpa login. **TIDAK** membuat order di tabel `orders` dan **TIDAK** mengurangi stok fisik (verifikasi stok & pembayaran manual via CS).

### 10.1 Buat Tautan WhatsApp Checkout
* **Endpoint:** `POST /guest/whatsapp-checkout`
* **Auth:** Tidak (Publik, rate limit 15 req/menit)

#### Request Body
```json
{
  "items": [
    {
      "sku_id": 1,
      "quantity": 2,
      "price_seen": 149000
    }
  ],
  "guest_name": "Budi Pendaki",
  "guest_phone": "081234567890",
  "note": "Kirim pagi ya kak"
}
```
*(Catatan: jika array `items` tidak dikirim atau kosong, server otomatis mengambil item dari session keranjang guest).*

#### Respon Berhasil (`201 Created`)
```json
{
  "success": true,
  "message": "Tautan WhatsApp pesanan berhasil dibuat.",
  "data": {
    "reference_code": "GC-20261011-4F2A",
    "wa_url": "https://wa.me/6281234567890?text=Halo%20Palo%20Mountain%20Goods...",
    "message": "Halo Palo Mountain Goods, saya ingin memesan:\n- Kaos Dryfit Senaru Summit (Hitam / M) x 2 = Rp 298.000\n\nEstimasi Total: Rp 298.000\nNama: Budi Pendaki\nNomor HP: 081234567890\nCatatan: Kirim pagi ya kak\nKode Referensi: GC-20261011-4F2A",
    "estimated_total": 298000,
    "estimated_total_display": "Rp 298.000",
    "notice": "Harga dan stok final dikonfirmasi Customer Service."
  }
}
```

### 10.2 Konsultasi Pesanan Custom / Partai (PRD §6A)
* **Endpoint:** `POST /guest/custom-inquiry`
* **Auth:** Tidak (Publik, rate limit 15 req/menit)

#### Request Body
```json
{
  "name": "Komunitas Mapala Rinjani",
  "phone": "081234567890",
  "quantity": 50,
  "note": "Pesanan jaket windbreaker bordir custom logo angkatan"
}
```

### 10.3 Checkout COD Tamu (Order Tanpa Login)
* **Endpoint:** `POST /guest/cod-checkout`
* **Auth:** Tidak (Publik, rate limit 15 req/menit)

Membuat order berstatus `awaiting_confirmation`, lalu memberi tautan WhatsApp CS. Berbeda dari §10.1, endpoint ini **membuat order** dan mengosongkan keranjang guest.

> **Perubahan perilaku:** field `shipping_cost`, `shipping_courier`, dan `shipping_service` **tidak lagi diterima** di body. Bila tetap dikirim, nilainya diabaikan server. Ongkir guest **belum dihitung** di sini karena CS mengonfirmasinya lewat WhatsApp.

#### Request Body
```json
{
  "shipping_address": {
    "recipient_name": "Pendaki Guest",
    "phone": "085522334455",
    "street": "Jl. Pariwisata Senaru No. 5",
    "city": "Lombok Utara",
    "province": "Nusa Tenggara Barat",
    "district": "Bayan",
    "postal_code": "83354",
    "notes": "Hubungi via WhatsApp dulu"
  },
  "items": [
    { "sku_id": 1, "quantity": 1, "price_seen": 750000 }
  ],
  "note": "Hubungi via WhatsApp dulu"
}
```
*(Catatan: bila `items` kosong, server memakai isi keranjang guest. Keranjang dikosongkan setelah order dibuat.)*

#### Respon Berhasil (`201 Created`)
```json
{
  "success": true,
  "message": "Pesanan COD berhasil dibuat.",
  "data": {
    "order_number": "PLR-20261024-00001",
    "cod_reference_code": "COD-20261024-A1B2C",
    "status": "menunggu_konfirmasi_cs",
    "subtotal": 750000,
    "discount_total": 0,
    "shipping_cost": 0,
    "grand_total": 750000,
    "grand_total_display": "Rp 750.000",
    "payment": {
      "method": "cod",
      "instructions": {
        "method": "cod",
        "cod_reference_code": "COD-20261024-A1B2C",
        "whatsapp_url": "https://wa.me/6281234567890?text=Halo%20Admin...",
        "whatsapp_message": "Halo Admin Palorinjani, saya ingin memesan dengan COD.\n...\nOngkir: akan dikonfirmasi CS\nTotal: Rp 750.000",
        "instructions_text": "Silakan konfirmasi pesanan Anda melalui WhatsApp ke Customer Service kami."
      },
      "whatsapp_url": "https://wa.me/6281234567890?text=Halo%20Admin..."
    },
    "whatsapp_url": "https://wa.me/6281234567890?text=Halo%20Admin...",
    "whatsapp_message": "Halo Admin Palorinjani, saya ingin memesan dengan COD.\n...",
    "notice": "Pesanan COD berhasil dicatat. Silakan konfirmasi pesanan via WhatsApp ke CS."
  }
}
```

> `grand_total` pada guest COD **belum termasuk ongkir** (`shipping_cost` selalu `0`). Tampilkan sebagai nilai sementara dan sertakan catatan bahwa ongkir dikonfirmasi CS — jangan beri label "total akhir".

---

## 11. Estimasi Ongkir & Pencarian Destinasi (Shipping)

Tarif dihitung **server-side** dari RajaOngkir V2. Nominal ongkir dari client tidak pernah dipakai.

### 11.1 Pencarian Destinasi (Autocomplete Alamat)
* **Endpoint:** `GET /shipping/destinations?search={keyword}&limit=10`
* **Auth:** Wajib (`Bearer <token>`, rate limit 60 req/menit)

`keyword` minimal 3 karakter; boleh nama kota, kecamatan, desa, atau kode pos. `limit` maksimal 50 (default 10).

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "data": {
    "destinations": [
      {
        "id": 17596,
        "label": "CEMPAKA PUTIH BARAT, CEMPAKA PUTIH, JAKARTA PUSAT, DKI JAKARTA, 10520",
        "province_name": "DKI JAKARTA",
        "city_name": "JAKARTA PUSAT",
        "district_name": "CEMPAKA PUTIH",
        "subdistrict_name": "CEMPAKA PUTIH BARAT",
        "zip_code": "10520"
      }
    ]
  }
}
```

**Cara pakai yang benar:** simpan `id` dari pilihan user sebagai `destination_id`, lalu kirim ke §11.2 dan §12.1. Jangan mengandalkan `label` mentah — formatnya milik RajaOngkir. Untuk tampilan, susun sendiri dari `subdistrict_name`, `city_name`, `province_name`, `zip_code` (contoh: `Cempaka Putih Barat, Jakarta Pusat, DKI Jakarta, 10520`).

Bila tidak ada hasil, `destinations` bernilai `[]` (tetap `200`, bukan error).

#### Error
| Kode | Keterangan |
|---|---|
| `422` | `search` kurang dari 3 karakter atau lebih dari 100 |
| `429` | Terlalu banyak pencarian (60/menit) |
| `503` | Layanan ongkir belum dikonfigurasi admin (`error_code: shipping_not_configured`) |

### 11.2 Kalkulasi Tarif Kurir
* **Endpoint:** `POST /shipping/quote`
* **Auth:** Wajib (`Bearer <token>`)

#### Request Body
```json
{
  "destination_id": 17596,
  "items": [
    { "sku_id": 1, "quantity": 2 }
  ]
}
```

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `destination_id` | integer | salah satu | Subdistrict ID dari §11.1. **Cara yang dianjurkan.** |
| `destination_city` | string | salah satu | Nama kota bebas. Hanya untuk frontend lama: server mencarikan ID-nya (memakan kuota API sekali per kata kunci). |
| `items` | array | tidak | Bila kosong, server memakai isi keranjang user yang sedang login. |

Minimal salah satu dari `destination_id` / `destination_city` harus ada.

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "data": {
    "origin": { "id": 746, "label": "Bayan, Lombok Utara, NTB, 83354" },
    "destination": { "id": 17596, "label": null },
    "weight_grams": 1200,
    "quoted_at": "2026-10-24T10:13:00+07:00",
    "source": "api",
    "services": [
      {
        "courier": "J&T",
        "courier_code": "jnt",
        "service": "EZ",
        "description": "Reguler",
        "cost": 13500,
        "etd": ""
      },
      {
        "courier": "JNE",
        "courier_code": "jne",
        "service": "REG",
        "description": "Layanan Reguler",
        "cost": 14000,
        "etd": "2 day"
      }
    ]
  }
}
```

| Field | Keterangan |
|---|---|
| `weight_grams` | Berat yang dikirim ke kurir, sudah termasuk berat volumetrik bila dimensi SKU terisi. |
| `quoted_at` | Waktu tarif diambil/dihitung. |
| `source` | `api` = baru diambil dari kurir · `cache` = dari cache ≤24 jam · `stale_cache` = cache lama dipakai karena kurir sedang bermasalah. |
| `services[].courier` | Nama tampilan (JNE, J&T, Lion, POS, …). |
| `services[].courier_code` | Kode internal kurir. Pakai ini bila perlu membandingkan, bukan `courier`. |
| `services[].cost` | Integer rupiah **apa adanya dari kurir**; bisa tidak bulat (mis. `40642`). Jangan dibulatkan. |
| `services[].etd` | String bebas dari kurir dan **bisa kosong** (`""`). Frontend wajib menangani kosong — jangan mengarang "2-3 hari". |
| `services[].description` | Tidak reliabel (sebagian kurir mengisinya dengan angka). Gunakan `service` sebagai label. |
| `destination.label` | Terisi hanya bila request memakai `destination_city`. Bila memakai `destination_id`, nilainya `null` — frontend sudah punya labelnya dari §11.1. |

`services` sudah diurutkan dari termurah. Layanan kargo (tarif ratusan ribu sampai jutaan rupiah) sudah difilter server. Bila tidak ada kurir yang melayani rute, `services` bernilai `[]`.

> **Ketersediaan kurir bergantung rute.** Kurir yang sama bisa muncul untuk satu kota dan tidak muncul untuk kota lain. Jangan meng-hardcode daftar kurir di frontend.

#### Respon Error
| Kode | `error_code` | Penyebab & tindakan frontend |
|---|---|---|
| `422` | `shipping_destination_not_found` | `destination_city` tidak dikenali. Arahkan user memilih dari §11.1. |
| `422` | `shipping_weight_too_heavy` | Berat pesanan melebihi batas kirim. Arahkan ke CS. |
| `503` | `shipping_not_configured` | Gudang asal belum diatur admin. Bukan kesalahan frontend. |
| `503` | `shipping_provider_unreachable` | Kurir tidak bisa dihubungi. Tampilkan pesan, izinkan coba lagi. |
| `503` | `shipping_provider_invalid_response` | Kurir membalas tidak valid atau error 5xx. Sama seperti di atas. |
| `502` | `shipping_provider_rejected` | Kurir menolak parameter yang dikirim (indikasi bug, bukan outage). Laporkan ke backend. |

---

## 12. Jalur B: Registered Online Checkout & Order Tracking (PRD §3.6)

Jalur formal transaksi online dengan penguncian stok sementara (*soft reservation* mengikuti jendela pembayaran DOKU, default 60 menit), validasi harga server-side, penerapan voucher, pembuatan record pesanan immutable, dan instruksi pembayaran dari DOKU Checkout.

### 12.1 Buat Pesanan Online (Submit Order)
* **Endpoint:** `POST /checkout/orders`
* **Auth:** Wajib (`Bearer <token>`, rate limit 10 req/menit)

#### Request Body
```json
{
  "shipping_address": {
    "recipient_name": "Rinjani Hiker",
    "phone": "081234567890",
    "street": "Jl. Pariwisata Senaru No. 12",
    "city": "Lombok Utara",
    "city_code": "2761",
    "province": "Nusa Tenggara Barat",
    "postal_code": "83354",
    "notes": "Titip pos satpam jika tidak di rumah"
  },
  "items": [
    {
      "sku_id": 1,
      "quantity": 2,
      "price_seen": 149000
    }
  ],
  "shipping_courier": "JNE",
  "shipping_service": "REG",
  "payment_method": "qris",
  "voucher_code": "RINJANI10",
  "acknowledge_price_change": false
}
```

**Field pengiriman yang wajib diperhatikan:**

| Field | Wajib | Keterangan |
|---|---|---|
| `shipping_address.destination_id` | dianjurkan | Subdistrict ID dari §11.1. Bila kosong, server mencarikan dari `shipping_address.city`. |
| `shipping_courier` | ya | Nama kurir dari `services[].courier` (§11.2), mis. `JNE` / `J&T`. |
| `shipping_service` | ya | Kode layanan dari `services[].service`, mis. `REG` / `EZ`. |
| `shipping_cost` | **tidak diterima** | Ongkir selalu dihitung ulang server-side dari tarif kurir terkini. Nilai yang dikirim akan diabaikan — jangan dikirim. |

Bila kombinasi `shipping_courier` + `shipping_service` tidak tersedia (mis. kurir berubah tarif atau rute tidak lagi dilayani), server menolak dengan `422` pada field `shipping_courier`. Frontend harus meminta user memilih ulang dari §11.2.

#### Respon Berhasil (`201 Created`)
```json
{
  "success": true,
  "message": "Pesanan berhasil dibuat.",
  "data": {
    "order_number": "PLR-20261011-00001",
    "status": "menunggu_pembayaran",
    "subtotal": 298000,
    "discount_total": 29800,
    "shipping_cost": 28000,
    "grand_total": 296200,
    "grand_total_display": "Rp 296.200",
    "payment_expires_at": "2026-10-11T17:00:00.000000Z",
    "payment": {
      "gateway": "doku",
      "method": "qris",
      "reference": "PLR-20261011-00001",
      "payment_url": "https://sandbox.doku.com/checkout/link/2ebffd22d23e436895ce5c38f7ddcf86",
      "instructions": {
        "method": "qris",
        "gateway": "doku",
        "payment_url": "https://sandbox.doku.com/checkout/link/2ebffd22d23e436895ce5c38f7ddcf86",
        "reference": "PLR-20261011-00001",
        "qr_string": null,
        "va_number": null,
        "bank_name": null,
        "instructions_text": "Selesaikan pembayaran melalui halaman pembayaran DOKU.",
        "expires_at": "2026-10-11T17:00:00+08:00"
      }
    }
  }
}
```

> **Snapshot ongkir:** order menyimpan tarif yang dipakai saat transaksi (`shipping_quote_snapshot`) beserta berat dan ID tujuan. Perubahan tarif kurir setelahnya **tidak** mengubah nilai order lama, jadi `data.shipping_cost` pada detail order adalah angka historis — bukan tarif terkini.

> **Perubahan perilaku (DOKU Checkout):** `data.payment.payment_url` adalah halaman pembayaran resmi DOKU. Frontend **wajib** mengarahkan pengguna ke URL tersebut (redirect/tombol "Bayar Sekarang") dan tidak boleh membuat QR/VA sendiri. `qr_string`/`va_number` dipertahankan bernilai `null` agar kontrak lama tidak pecah. Status pembayaran final diterima backend lewat notifikasi DOKU (`POST /api/v1/payments/webhook/doku`, tanpa auth, diverifikasi signature) dengan fallback rekonsiliasi harian.

#### Respon Error Khusus Checkout

##### A. Deteksi Perubahan Harga (`409 Conflict` - `error_code: price_changed`)
Jika harga produk telah diubah oleh Admin sejak pengguna membuka keranjang dan pengguna belum menyetujui perubahan harga:
```json
{
  "success": false,
  "message": "Harga produk berubah sejak keranjang dibuka. Tinjau perubahannya sebelum melanjutkan.",
  "error_code": "price_changed",
  "changes": [
    {
      "sku_id": 1,
      "sku_code": "KDS-HIT-M",
      "product_name": "Kaos Dryfit Senaru Summit",
      "old_price": 129000,
      "new_price": 149000,
      "quantity": 2
    }
  ],
  "new_grand_total": 326000
}
```
*Frontend wajib menampilkan perbandingan harga ke pengguna dan mengirim ulang request dengan `"acknowledge_price_change": true`.*

##### B. Stok Habis / Tidak Cukup (`409 Conflict` - `error_code: stock_unavailable`)
```json
{
  "success": false,
  "message": "Stok SKU KDS-HIT-M (Kaos Dryfit Senaru Summit) tidak mencukupi (tersedia: 1, diminta: 2).",
  "error_code": "stock_unavailable",
  "sku_code": "KDS-HIT-M",
  "available_stock": 1,
  "requested_quantity": 2
}
```

### 12.2 Riwayat Pesanan Pengguna
* **Endpoint:** `GET /orders`
* **Auth:** Wajib (`Bearer <token>`)

### 12.3 Detail Snapshot Pesanan
* **Endpoint:** `GET /orders/{orderNumber}`
* **Auth:** Wajib (`Bearer <token>`)

---

## 13. Pembayaran (DOKU Checkout)

Pembayaran non-COD memakai **DOKU Checkout** (hosted payment page). Backend tidak membuat QR atau nomor VA sendiri: semua metode diselesaikan di halaman DOKU, dan status final dikirim DOKU ke backend.

### 13.1 Alur Wajib di Frontend

1. `POST /checkout/orders` dengan `payment_method` pilihan user (§12.1).
2. Ambil `data.payment.payment_url`.
3. Arahkan user ke URL itu (redirect penuh atau tombol "Bayar Sekarang"). **Jangan** dibuka di dalam iframe.
4. Setelah user menyelesaikan pembayaran, DOKU mengembalikan ke halaman toko. Status order **belum tentu** berubah saat itu — backend menunggu notifikasi DOKU (biasanya beberapa detik).
5. Poll `GET /orders/{orderNumber}` (mis. tiap 3–5 detik, hentikan setelah 2 menit) sampai `data.status` berubah dari `menunggu_pembayaran`.

### 13.2 Metode Pembayaran yang Diterima

Nilai `payment_method` pada `POST /checkout/orders`:

| Nilai | Metode di DOKU |
|---|---|
| `qris` | QRIS |
| `virtual_account` | Virtual Account (BCA, BRI, BNI, Mandiri, Permata, CIMB, DOKU) |
| `ewallet` | OVO, DANA, ShopeePay, Doku Wallet, LinkAja |
| `bank_transfer` | Virtual Account bank |
| `credit_card` | Kartu kredit |
| `cod` | Bayar di tempat — **bukan** DOKU (§10.3 & §12.1) |

### 13.3 Batas Waktu Pembayaran

`data.payment_expires_at` adalah batas waktu pembayaran **dan** masa penguncian stok (default **60 menit**). Setelah lewat, penguncian stok dilepas dan kuota voucher dikembalikan.

> **Status akhirnya `expired`.** Order online yang tidak dibayar sampai batas waktu dan COD yang tidak dikonfirmasi CS sama-sama berakhir `expired` (`kedaluwarsa`). `dibatalkan` (`cancelled`) hanya dipakai bila admin atau CS membatalkan pesanan secara sengaja.

Tampilkan hitung mundur dari `payment_expires_at`; jangan menghitung sendiri dari waktu request.

### 13.4 Status Pembayaran

`data.payment.status` pada `GET /orders/{orderNumber}`:

| Status | Arti | Tindakan frontend |
|---|---|---|
| `pending` | Belum dibayar | Tampilkan tombol bayar + hitung mundur |
| `settlement` | Lunas terverifikasi | Hentikan polling, tampilkan halaman sukses |
| `capture` | Dana ter-capture (kartu kredit) | Perlakukan sama seperti `settlement` |
| `deny` / `cancel` | Ditolak / dibatalkan | Tampilkan gagal, tawarkan buat pesanan baru |
| `expire` | Kedaluwarsa | Tampilkan kedaluwarsa |
| `refund` | Dana dikembalikan | Tampilkan info refund |

Bila `data.needs_reconciliation` bernilai `true`, status pembayaran dan status order saling bertentangan (mis. sudah dibayar tetapi stok lepas). Tampilkan pesan "sedang diverifikasi CS" dan jangan menganggapnya gagal.

### 13.5 Notifikasi Pembayaran (Webhook — internal, bukan untuk frontend)

* **Endpoint:** `POST /api/v1/payments/webhook/doku`
* **Auth:** Tidak ada. Keaslian diverifikasi lewat signature HMAC-SHA256 (`Client-Id`, `Request-Id`, `Request-Timestamp`, `Signature`) memakai `Request-Target` yang sama dengan `DOKU_NOTIFICATION_PATH`.

Endpoint ini **hanya dipanggil DOKU**. Frontend tidak boleh memanggilnya.

| Respon | Arti |
|---|---|
| `200` | Diterima, atau duplikat yang sudah pernah diproses (aman, idempoten) |
| `400` | Payload tidak dikenali |
| `401` | Signature tidak valid |
| `404` | Order untuk notifikasi tersebut tidak ditemukan (dicatat di log) |
| `500` | Gagal diproses; DOKU akan mengirim ulang |

Sifat pemrosesan:
* **Idempoten** — notifikasi berulang bertanda `external_event_id` sama hanya diproses sekali, sehingga pembayaran tidak tergandakan.
* **Nominal diverifikasi** — bila nominal notifikasi tidak sama dengan tagihan, payment ditandai untuk rekonsiliasi manual dan order diberi `needs_reconciliation: true`.

### 13.6 Rekonsiliasi Otomatis

| Proses | Jadwal | Fungsi |
|---|---|---|
| `payments:reconcile-doku` | Harian, 02:00 | Membandingkan payment `pending` dengan Check Status API DOKU untuk menangkap notifikasi yang hilang |
| `orders:expire-unpaid` | Tiap menit | Menandai order `pending` yang melewati batas waktu sebagai `expired` dan melepas stok |
| `orders:expire-unconfirmed-cod` | Tiap jam | Menandai order COD yang tidak dikonfirmasi CS sebagai `expired` |

---

## 14. Voucher & Promosi (PRD §3.6, §3.8, §3.12)

Fitur voucher dan diskon promosi untuk mendapatkan potongan harga pada pesanan belanja. Voucher dapat berupa nominal tetap (*fixed*) atau persentase (*percentage*) dengan batas kuota dan periode berlaku.

### 14.1 Daftar Voucher Aktif & Publik
Mengambil daftar voucher aktif yang saat ini berlaku, memiliki sisa kuota, dan dapat digunakan oleh pelanggan di storefront/halaman promo.

* **Endpoint:** `GET /vouchers`
* **Auth:** Bebas (Publik / Opsional Token)
* **Rate Limit:** 60 request / menit

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Daftar voucher promosi berhasil diambil.",
  "data": [
    {
      "id": 1,
      "code": "RINJANI10",
      "type": "percentage",
      "value": 10,
      "formatted_value": "10%",
      "min_purchase": 100000,
      "formatted_min_purchase": "Rp 100.000",
      "start_date": "2026-10-01",
      "end_date": "2026-10-31",
      "description": "Potongan 10% dengan minimal belanja Rp 100.000"
    },
    {
      "id": 2,
      "code": "HEMAT50K",
      "type": "fixed",
      "value": 50000,
      "formatted_value": "Rp 50.000",
      "min_purchase": 300000,
      "formatted_min_purchase": "Rp 300.000",
      "start_date": "2026-10-01",
      "end_date": "2026-10-31",
      "description": "Potongan Rp 50.000 dengan minimal belanja Rp 300.000"
    }
  ]
}
```

---

### 14.2 Validasi & Simulasi Voucher (Apply Voucher)
Memvalidasi kode voucher terhadap subtotal belanja atau daftar item keranjang sebelum checkout. Endpoint ini menghitung potensi potongan diskon tanpa mengunci kuota atau mengubah status keranjang.

* **Endpoint:** `POST /vouchers/apply`
* **Auth:** Bebas (Publik / Opsional Token)
* **Rate Limit:** 10 request / menit (`throttle:10,1`)

#### Request Body
| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `code` | string | Ya | Kode voucher (case-insensitive, otomatis dinormalisasi menjadi huruf kapital). |
| `subtotal` | numeric | Kondisional | Subtotal belanja saat ini dalam Rupiah (min: 0). Wajib jika `items` tidak disertakan. |
| `items` | array | Kondisional | Daftar item keranjang jika subtotal dihitung otomatis oleh server (min: 1 item). |
| `items.*.sku_id` | integer | Ya (jika items) | ID SKU yang valid dan aktif di database. |
| `items.*.quantity` | integer | Ya (jika items) | Kuantitas belanja barang (min: 1). |

```json
{
  "code": "RINJANI10",
  "subtotal": 250000
}
```

#### Respon Berhasil (`200 OK`)
```json
{
  "success": true,
  "message": "Voucher berhasil diterapkan.",
  "data": {
    "voucher_id": 1,
    "code": "RINJANI10",
    "type": "percentage",
    "value": 10,
    "formatted_value": "10%",
    "min_purchase": 100000,
    "formatted_min_purchase": "Rp 100.000",
    "discount_amount": 25000,
    "formatted_discount_amount": "Rp 25.000",
    "subtotal": 250000,
    "formatted_subtotal": "Rp 250.000",
    "final_subtotal": 225000,
    "formatted_final_subtotal": "Rp 225.000"
  }
}
```

#### Respon Gagal Validasi Bisnis (`422 Unprocessable Content`)
Mengembalikan envelope error standar `success: false` dengan pesan spesifik kegagalan validasi:
* `"Kode voucher tidak ditemukan atau tidak valid."`
* `"Voucher sedang tidak aktif."`
* `"Voucher belum dapat digunakan (berlaku mulai dd/mm/yyyy)."`
* `"Voucher telah kedaluwarsa."`
* `"Kuota pemakaian voucher telah habis."`
* `"Syarat belanja minimum Rp 100.000 belum terpenuhi."`

```json
{
  "success": false,
  "message": "Kode voucher tidak ditemukan atau tidak valid.",
  "errors": {
    "code": [
      "Kode voucher tidak ditemukan atau tidak valid."
    ]
  }
}
```

---

## 15. Ringkasan Kode Status HTTP (HTTP Status Codes)

| Kode | Status | Keterangan untuk Frontend |
|---|---|---|
| `200` | **OK** | Request berhasil, data terlampir di body respon. |
| `201` | **Created** | Resource baru berhasil dibuat (pesanan, registrasi, log checkout). |
| `401` | **Unauthorized** | Kredensial tidak cocok, token Bearer tidak valid, kadaluarsa, atau tidak dikirim. |
| `403` | **Forbidden** | Pengguna terautentikasi tetapi tidak memiliki hak akses terhadap resource. |
| `404` | **Not Found** | Endpoint atau resource (produk/koleksi/pesanan) tidak ditemukan. |
| `409` | **Conflict** | Terjadi konflik transaksi stok habis (`stock_unavailable`) atau perubahan harga (`price_changed`). |
| `422` | **Unprocessable Content** | Validasi payload gagal (misal email duplikat, voucher kadaluarsa). Periksa objek `errors`. |
| `429` | **Too Many Requests** | Terkena limitasi frekuensi request (auth/checkout > 10x/menit, guest > 15x/menit). Periksa header `Retry-After`. |
| `502` | **Bad Gateway** | Penyedia eksternal (kurir) menolak permintaan karena parameter tidak valid (`error_code: shipping_provider_rejected`). Indikasi bug backend, bukan kesalahan frontend. |
| `503` | **Service Unavailable** | Layanan eksternal sedang bermasalah atau belum dikonfigurasi: kurir tidak bisa dihubungi (`shipping_provider_unreachable`, `shipping_provider_invalid_response`) atau gudang asal belum diatur admin (`shipping_not_configured`). |
| `500` | **Internal Server Error** | Terjadi kesalahan server backend. |


