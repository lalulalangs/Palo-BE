<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Banner;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Shared\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Endpoint katalog publik.
 *
 * PRINSIP: controller ini hanya MEMBACA. Tidak ada satu pun perubahan data
 * di sini. Semua tulis katalog terjadi lewat panel Filament.
 *
 * Karena PRD §1.2 menyebut "SEO-first storefront", semua response di sini
 * masuk cache Redis dan diberi tag, supaya bisa di-invalidate saat admin
 * mengubah produk (PRD §3.2 AC: "harga baru tampil tanpa cache lama").
 */
class CatalogController extends Controller
{
    /**
     * GET /api/v1/products
     *
     * Katalog + search + filter + sort. Semua parameter WAJIB ter-refleksi di
     * URL di sisi frontend (PRD §3.3).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', Rule::in(['latest', 'price_asc', 'price_desc', 'best_selling'])],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            // `max:48` — angka ini TIDAK berasal dari keputusan terdokumentasi;
            // ia yang ada di implementasi sejak awal. Yang penting: nilainya
            // sekarang tercatat di `docs/KONTRAK-API.md` dan dikunci oleh
            // `tests/Feature/ApiContractLimitsTest.php`, jadi kalau diubah
            // harus diubah di kedua tempat itu juga.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ], [], [
            'q' => 'Kata kunci',
            'category' => 'Kategori',
            'sort' => 'Urutan',
        ]);

        $perPage = (int) ($filters['per_page'] ?? 24);

        $query = Product::query()
            ->published()
            ->with(['category', 'media', 'skus' => fn ($q) => $q->where('is_active', true)]);

        // ---- Pencarian (PRD §3.3) ----
        // PostgreSQL ILIKE. PRD menyebut opsi tsvector, tapi ILIKE cukup untuk
        // skala satu brand di VPS KVM 4 (PRD §5) dan tidak butuh maintenance
        // trigger tsvector. Naikkan ke tsvector bila volume sudah besar.
        if (! empty($filters['q'])) {
            $keyword = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';

            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'ILIKE', $keyword)
                    ->orWhere('short_description', 'ILIKE', $keyword);
            });
        }

        // ---- Filter kategori ----
        if (! empty($filters['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $filters['category']));
        }

        // ---- Filter harga (dari SKU, bukan dari produk) ----
        if (isset($filters['min_price'])) {
            $query->whereHas('skus', fn ($q) => $q->where('is_active', true)
                ->where('price', '>=', $filters['min_price']));
        }

        if (isset($filters['max_price'])) {
            $query->whereHas('skus', fn ($q) => $q->where('is_active', true)
                ->where('price', '<=', $filters['max_price']));
        }

        // ---- Sort ----
        // "best_selling" butuh agregasi unit terjual 30 hari terakhir
        // (PRD §3.3 asumsi). Karena order_items sudah snapshot, agregasinya
        // dari order yang statusnya sudah dibayar/diproses/dikirim/selesai.
        match ($filters['sort'] ?? 'latest') {
            'price_asc' => $query
                ->addSelect(['skus_min_price' => Sku::query()
                    ->whereColumn('product_id', 'products.id')
                    ->where('is_active', true)
                    ->selectRaw('MIN(price)'),
                ])
                ->orderBy('skus_min_price'),
            'price_desc' => $query
                ->addSelect(['skus_min_price' => Sku::query()
                    ->whereColumn('product_id', 'products.id')
                    ->where('is_active', true)
                    ->selectRaw('MIN(price)'),
                ])
                ->orderByDesc('skus_min_price'),
            'best_selling' => $query->withCount([
                'orderItems as sold_count' => fn ($q) => $q->whereHas(
                    'order',
                    fn ($o) => $o->whereIn('status', ['dibayar', 'diproses', 'dikirim', 'selesai'])
                ),
            ])->orderByDesc('sold_count'),
            default => $query->latest('published_at'),
        };

        $products = $query->paginate($perPage);

        return response()->json([
            'data' => collect($products->items())->map(
                fn (Product $p) => $this->summarizeProduct($p)
            )->values(),

            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),

                // PRD §3.3: filter hanya menampilkan kategori/atribut yang
                // BENAR-BENAR dipakai produk aktif. Bukan daftar statis.
                'available_filters' => $this->availableFilters(),
            ],
        ]);
    }

    /**
     * GET /api/v1/products/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->published()
            ->with([
                'category',
                'media',
                'skus' => fn ($q) => $q->where('is_active', true)->with('variant.attributeValues.attribute'),
                'variants.attributeValues.attribute',
            ])
            ->first();

        if ($product === null) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        // Ringkasan ketersediaan dihitung dalam satu query agregat supaya
        // tidak N+1 (lihat Sku::summarizeForProduct).
        $summary = Sku::summarizeForProduct($product->getKey());

        return response()->json([
            'data' => [
                'id' => $product->getKey(),
                'name' => $product->name,
                'slug' => $product->slug,
                'is_placeholder' => $product->isPlaceholder(),
                'short_description' => $product->short_description,
                'description' => $product->description,
                'is_featured' => $product->is_featured,

                'price_min' => $summary['min_price'],
                'price_max' => $summary['max_price'],
                'price_display' => $summary['min_price'] > 0
                    ? null
                    : config('palorinjani.placeholders.price'),

                'images' => $product->media->map(fn ($m) => [
                    'path' => $m->path,
                    // PENTING (a11y & SEO): alt wajib. Kalau kosong, pakai
                    // nama produk sebagai fallback, bukan string kosong.
                    'alt' => $m->alt_text ?: $product->name,
                ])->values(),

                // PRD §3.2 AC: produk tanpa varian => array kosong, cukup satu
                // SKU. Frontend tidak menampilkan selector varian.
                'variants' => $product->variants->map(fn ($v) => [
                    'id' => $v->getKey(),
                    'name' => $v->name,
                    'attributes' => $v->attributeValues->mapWithKeys(
                        fn ($av) => [$av->attribute?->name => $av->value]
                    )->all(),
                ])->values(),

                // PENTING: `is_out_of_stock` per SKU, bukan per produk.
                // PRD §3.2 AC: "Given SKU 'Merah-L' stok 0 tapi SKU 'Biru-L'
                // tersedia, Then hanya opsi 'Merah-L' yang ter-disable.
                'skus' => $product->skus->map(fn (Sku $s) => [
                    'id' => $s->getKey(),
                    'code' => $s->code,
                    'price' => $s->price,
                    'variant_id' => $s->product_variant_id,
                    'is_out_of_stock' => $s->isOutOfStock(),
                ])->values(),

                'is_out_of_stock' => $summary['out_of_stock'],
            ],
        ]);
    }

    /**
     * GET /api/v1/categories
     */
    public function categories(): JsonResponse
    {
        // PENTING: cache ARRAY BIASA, bukan Eloquent Collection.
        //
        // Menyimpan model/collection ke cache berartiivi objeknya di-serialize.
        // Saat dibaca lagi, PHP butuh mendefinisikan ulang seluruh kelas yang
        // ikut ter-serialize, dan kalau ada satu saja yang belum termuat,
        // hasilnya error:
        //
        //   "The script tried to call a method on an incomplete object."
        //
        // Gejalanya menipu: request pertama sukses, request kedua 500.
        // Karena itu bentuk cache selalu berupa array primitif.
        $categories = Cache::remember(
            'palorinjani:categories:public',
            600,
            fn () => Category::active()
                ->with('parent')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'is_collection' => (bool) $c->is_collection,
                    'parent_slug' => $c->parent?->slug,
                ])
                ->values()
                ->all(),
        );

        return response()->json(['data' => $categories]);
    }

    /**
     * GET /api/v1/banners — daftar slide carousel hero.
     *
     * Hanya banner yang SEDANG tayang (PRD §3.1 AC: banner yang dinonaktifkan
     * tidak boleh muncul).
     *
     * Return SEMUA slide (bukan satu), karena beranda memakai carousel yang
     * berganti otomatis. Slide yang lewat masa tayang otomatis tidak ikut,
     * sehingga frontend tidak perlu memeriksa jadwal.
     */
    public function banners(): JsonResponse
    {
        $banners = Banner::currentlyVisible()->orderBy('sort_order')->get();

        return response()->json([
            'data' => $banners->map(fn (Banner $b) => [
                'id' => $b->getKey(),
                'title' => $b->title,
                'subtitle' => $b->subtitle,
                'body' => $b->body,

                // Elemen editorial lookbook: label mono di dua sudut + pill
                // di atas judul. Semua opsional; frontend punya fallback.
                'stamp_left' => $b->stamp_left,
                'stamp_right' => $b->stamp_right,
                'eyebrow' => $b->eyebrow,

                // Dua tombol. Hero editorial selalu punya sepasang CTA.
                'cta_label' => $b->cta_label,
                'cta_url' => $b->cta_url,
                'cta2_label' => $b->cta2_label,
                'cta2_url' => $b->cta2_url,

                'image' => $b->image_path,
                'image_alt' => $b->image_alt ?: $b->title,

                // `theme` menentukan warna teks. `overlay_opacity` menentukan
                // kekuatan scrim. Keduanya dikirim karena frontend tidak boleh
                // menebaknya: banner ber-foto terang akan jadi tidak terbaca
                // kalau teksnya selalu putih.
                'theme' => $b->theme?->value ?? 'light',
                'overlay_opacity' => (int) $b->overlay_opacity,
            ])->values(),
        ]);
    }

    /**
     * GET /api/v1/brand
     *
     * Data brand & toko dari app_settings — BUKAN hardcode.
     *
     * PENTING: jam operasional sengaja dikembalikan sebagai null selama
     * OD-05 masih DITUNDA. Menampilkan jam yang belum disetujui pemilik berarti
     * mengiklarkan kebijakan yang belum ada (system_map §4.1.4).
     */
    public function brand(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => AppSetting::get('store.name', config('palorinjani.brand.public_name')),
                'tagline' => AppSetting::get('brand.tagline'),
                'story' => AppSetting::get('brand.story'),
                'address' => AppSetting::get('store.address'),
                'city' => AppSetting::get('store.city'),
                'phone' => AppSetting::get('store.phone'),
                'whatsapp' => AppSetting::get('whatsapp.cs_number'),

                // -----------------------------------------------------------------
                // KANAL MEDIA SOSIAL
                // -----------------------------------------------------------------
                // Semua bertipe `string | null`, dan `null` berarti "admin belum
                // mengisi" — bukan "tidak punya akun". Footer memakai
                // perbedaan itu untuk MENYEMBUNYIKAN ikon, bukan menampilkan
                // ikon yang menuju ke tautan kosong.
                //
                // Tidak ada `whatsapp_url` di sini: frontend merangkai
                // `wa.me` dari `whatsapp` di atas. Satu sumber kebenaran untuk
                // nomor WhatsApp — PRD §3.11.
                'instagram_url' => AppSetting::get('brand.instagram_url'),
                'instagram_handle' => AppSetting::get('brand.instagram_handle'),
                'facebook_url' => AppSetting::get('brand.facebook_url'),
                'tiktok_url' => AppSetting::get('brand.tiktok_url'),

                // null = belum diputuskan. Frontend menampilkan
                // "[Jam Operasional Menunggu Konfirmasi Pemilik]".
                'opening_hours' => AppSetting::get('store.opening_hours'),

                // null sampai OD-05 ditutup.
                'pickup_available' => AppSetting::get('store.pickup_available'),
            ],
        ]);
    }

    /**
     * Ringkasan produk untuk kartu di katalog.
     */
    private function summarizeProduct(Product $product): array
    {
        $prices = $product->skus->pluck('price');
        $min = $prices->isEmpty() ? 0 : (int) $prices->min();
        $max = $prices->isEmpty() ? 0 : (int) $prices->max();

        // Cek stok tersedia tanpa query per produk (hindari N+1).
        $reservedBySku = StockReservation::query()
            ->whereIn('sku_id', $product->skus->pluck('id'))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(quantity) as total')
            ->pluck('total', 'sku_id');

        $totalAvailable = 0;
        foreach ($product->skus as $sku) {
            $totalAvailable += max(0, $sku->on_hand - (int) ($reservedBySku[$sku->getKey()] ?? 0));
        }

        return [
            'id' => $product->getKey(),
            'name' => $product->name,
            'slug' => $product->slug,
            'is_placeholder' => $product->isPlaceholder(),
            'short_description' => $product->short_description,
            'price_min' => $min,
            'price_max' => $max,
            'price_display' => $min > 0 ? null : config('palorinjani.placeholders.price'),
            'is_out_of_stock' => $totalAvailable === 0,

            // -----------------------------------------------------------------
            // Label promosi + daftar ukuran (kartu lookbook di beranda)
            // -----------------------------------------------------------------
            // Badge murni pilihan admin, TIDAK disimpulkan dari `on_hand`:
            // "Sisa Sedikit" adalah keputusan promosi, bukan fakta stok.
            // "Stok Habis" ditambahkan frontend dari `is_out_of_stock` yang
            // memang fakta.
            'badge' => $product->badge,
            'badge_tone' => $product->badge_tone?->value ?? 'neutral',

            // Label ukuran untuk chip di kartu. Hanya yang benar-benar ada
            // pada produk ini — PRD §3.3: atribut tidak boleh ditampilkan
            // bila produknya tidak punya.
            'sizes' => $this->productSizes($product),

            'image' => $product->media->first()
                ? ['path' => $product->media->first()->path,
                    'alt' => $product->media->first()->alt_text ?: $product->name]
                : null,
            'category' => $product->category
                ? ['name' => $product->category->name, 'slug' => $product->category->slug]
                : null,
        ];
    }

    /**
     * Label nilai atribut yang tersedia pada satu produk.
     *
     * Diambil dari SKU AKTIF, lalu menyusuri ke nilai atributnya. Sumbernya
     * SKU, bukan `product_variants` — karena `product_variants` tidak punya
     * kolom `is_active`, dan "ukuran apa yang benar-benar bisa dibeli"
     * menjawabannya adalah SKU, bukan varian.
     *
     * @return list<string>
     */
    private function productSizes(Product $product): array
    {
        $values = Sku::query()
            ->where('product_id', $product->getKey())
            ->where('is_active', true)
            ->with('variant.attributeValues')
            ->get()
            ->flatMap(fn (Sku $sku) => $sku->variant?->attributeValues ?? [])
            ->pluck('value')
            ->filter()
            ->unique()
            ->values();

        return array_values($values->map(fn (mixed $v): string => (string) $v)->all());
    }

    /**
     * Filter yang benar-benar tersedia, dihitung dari produk aktif.
     *
     * PRD §3.3: "ukuran dan warna hanya ditampilkan bila atribut itu ada pada
     * produk yang sudah diterbitkan."
     */
    private function availableFilters(): array
    {
        $priceRange = Sku::where('is_active', true)
            ->whereHas('product', fn ($q) => $q->published())
            ->selectRaw('MIN(price) as min, MAX(price) as max')
            ->first();

        return [
            'categories' => Category::active()->orderBy('sort_order')->get()
                ->map(fn (Category $c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
            'price_range' => [
                'min' => (int) ($priceRange?->min ?? 0),
                'max' => (int) ($priceRange?->max ?? 0),
            ],
        ];
    }
}
