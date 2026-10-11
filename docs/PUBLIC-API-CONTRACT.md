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
Jika terjadi kegagalan validasi (HTTP 422), format respon:
```json
{
  "message": "Pesan ringkas kegagalan validasi",
  "errors": {
    "field_name": [
      "Pesan error spesifik untuk field ini"
    ]
  }
}
```

Jika terjadi error autentikasi / hak akses / rate limit (HTTP 401, 403, 429):
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
    "products": [
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
    ]
  },
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 12,
    "total": 6,
    "has_more": false
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
  "status": "success",
  "data": [
    {
      "id": 1,
      "series_tag": "Rinjani 2026",
      "location_tag": "Senaru, Lombok Utara",
      "badge": "Terbaru",
      "title": "Jelajahi Puncak Rinjani Bersama Palo",
      "description": "Perlengkapan ekspedisi berkualitas tinggi teruji di jalur ekstrem.",
      "image_desktop": "http://localhost:8000/storage/heroes/desktop-1.webp",
      "image_mobile": "http://localhost:8000/storage/heroes/mobile-1.webp",
      "image_alt": "Pemandangan Jalur Senaru Rinjani",
      "primary_btn_label": "Belanja Sekarang",
      "primary_btn_url": "/produk",
      "secondary_btn_label": "Lihat Koleksi",
      "secondary_btn_url": "/koleksi",
      "sort_order": 1,
      "is_active": true
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
  "status": "success",
  "data": {
    "id": 1,
    "series_tag": "Rinjani 2026",
    "location_tag": "Senaru, Lombok Utara",
    "badge": "Terbaru",
    "title": "Jelajahi Puncak Rinjani Bersama Palo",
    "description": "Perlengkapan ekspedisi berkualitas tinggi teruji di jalur ekstrem.",
    "image_desktop": "http://localhost:8000/storage/heroes/desktop-1.webp",
    "image_mobile": "http://localhost:8000/storage/heroes/mobile-1.webp",
    "image_alt": "Pemandangan Jalur Senaru Rinjani",
    "primary_btn_label": "Belanja Sekarang",
    "primary_btn_url": "/produk",
    "secondary_btn_label": "Lihat Koleksi",
    "secondary_btn_url": "/koleksi",
    "sort_order": 1,
    "is_active": true
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

## 9. Ringkasan Kode Status HTTP (HTTP Status Codes)

| Kode | Status | Keterangan untuk Frontend |
|---|---|---|
| `200` | **OK** | Request berhasil, data terlampir di body respon. |
| `201` | **Created** | Resource baru berhasil dibuat (misal pendaftaran akun). |
| `401` | **Unauthorized** | Kredensial tidak cocok, token Bearer tidak valid, kadaluarsa, atau tidak dikirim. |
| `403` | **Forbidden** | Pengguna terautentikasi tetapi tidak memiliki hak akses terhadap resource. |
| `404` | **Not Found** | Endpoint atau resource (produk/koleksi/slider) tidak ditemukan atau berstatus non-aktif. |
| `422` | **Unprocessable Content** | Validasi payload gagal. Periksa objek `errors` untuk menampilkan pesan per field input form. |
| `429` | **Too Many Requests** | Terkena limitasi frekuensi request (misal registrasi/login > 10x per menit). Periksa header `Retry-After`. |
| `500` | **Internal Server Error** | Terjadi kesalahan pada server backend. Tampilkan pesan kesalahan ramah pengguna. |
