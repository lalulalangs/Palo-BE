<?php

namespace Database\Seeders;

use App\Domain\Catalog\Enums\BadgeTone;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Banner;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Shared\Models\AppSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Seeder foto & katalog dari aset asli pemilik.
 *
 * ===================================================================
 *  DARI MANA FOTO PRODUK
 * ===================================================================
 * Foto produk diambil dari `drive_asset/` (1080x1350) — foto KOLABORASI
 * terbaru, bukan foto potret closeup yang hanya ~430px. Perbedaannya nyata:
 * halaman detail memakai slot 45rem (720px), jadi foto 430px selalu
 * otomatis dibesar dan terlihat kabur. Foto 1080px menutupi rentang itu.
 *
 * Manifest yang dibaca: `drive_asset/manifest.json`, ditulis oleh
 * `scripts/prepare_drive_assets.py`.
 *
 * ===================================================================
 *  BUKAN `DatabaseSeeder`
 * ===================================================================
 * `DatabaseSeeder` sengaja tidak membuat produk apa pun (PRD §1.1A
 * melarang produk contoh). Seeder ini berbeda: isinya foto dan nama desain
 * yang BENAR-BENAR terbaca di kaos, jadi bukti — bukan asumsi. Yang tetap
 * placeholder adalah hal yang tidak terlihat di foto: harga, bahan, berat.
 *
 * Jalankan:
 *     python3 scripts/prepare_drive_assets.py
 *     php artisan db:seed --class="Database\\Seeders\\MediaSeeder"
 */
class MediaSeeder extends Seeder
{
    /**
     * Meta produk.
     *
     * `slot` = nama aset di `drive_asset/manifest.json` (lihat
     * `NAMA_SLOT` di `scripts/prepare_drive_assets.py`).
     *
     * `badge` = label promosi. Ini keputusan merchandising pemilik, jadi
     * diberi nilai di sini sebagai titik awal yang bisa diubah dari panel
     * admin — BUKAN dihitung dari `on_hand` (lihat migration badge).
     *
     * @var array<int, array{name: string, slot: string, badge: ?string, badge_tone: string, sizes: array<int, string>}>
     */
    private const PRODUK = [
        1 => ['name' => 'Chase The Edge', 'slot' => 'chase-the-edge', 'badge' => 'Batch 01', 'badge_tone' => 'neutral', 'sizes' => ['S', 'M', 'L', 'XL']],
        2 => ['name' => 'Crater of Serenity', 'slot' => 'crater-of-serenity', 'badge' => 'Signature', 'badge_tone' => 'neutral', 'sizes' => ['S', 'M', 'L']],
        3 => ['name' => 'Rinjani Wanderers', 'slot' => 'rinjani-wanderers', 'badge' => null, 'badge_tone' => 'neutral', 'sizes' => ['L']],
        4 => ['name' => 'Rinjani Collection', 'slot' => 'rinjani-collection', 'badge' => 'Edisi Senaru', 'badge_tone' => 'neutral', 'sizes' => ['S', 'M', 'L', 'XL']],
        5 => ['name' => 'Coffee With A View', 'slot' => 'coffee-with-a-view', 'badge' => null, 'badge_tone' => 'neutral', 'sizes' => ['L']],
        6 => ['name' => 'Route to Heaven', 'slot' => 'route-to-heaven', 'badge' => null, 'badge_tone' => 'neutral', 'sizes' => ['L']],
    ];

    public function run(): void
    {
        $manifestPath = base_path('../drive_asset/manifest.json');

        if (! File::exists($manifestPath)) {
            $this->command?->error('Manifest drive_asset tidak ditemukan. Jalankan: python3 scripts/prepare_drive_assets.py');

            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        $category = Category::firstOrCreate(
            ['slug' => 'apparel'],
            [
                'name' => 'Apparel',
                'description' => 'Koleksi dari Palo Mountain Goods.',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $this->command?->info('Memuat foto produk dari drive_asset (1080px)...');

        foreach (self::PRODUK as $index => $meta) {
            $slot = $meta['slot'];
            $varian = $this->varianTerlebar($this->slotVarian($manifest, $slot));

            if ($varian === null) {
                $this->command?->warn(sprintf(
                    '  Produk "%s" dilewati: aset slot "%s" belum ada.',
                    $meta['name'],
                    $slot,
                ));

                continue;
            }

            $product = $this->makeProduct($category, $index, $meta);
            $this->attachMedia($product, $varian, $meta['name']);
            $this->attachVariants($product, $meta['sizes']);
        }

        $this->makeBanners($manifest);
        $this->registerBrandMedia();

        $this->command?->info('Selesai. Harga & bahan tetap placeholder sampai pemilik mengonfirmasi.');
    }

    /**
     * Cari varian-varian sebuah slot di manifest drive.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function slotVarian(array $manifest, string $slot): array
    {
        foreach ($manifest['gambar'] ?? [] as $item) {
            if (is_array($item) && ($item['slot'] ?? null) === $slot) {
                return is_array($item['variants'] ?? null) ? $item['variants'] : [];
            }
        }

        return [];
    }

    /**
     * Buat produk + SKU default.
     */
    private function makeProduct(Category $category, int $index, array $meta): Product
    {
        $product = Product::updateOrCreate(
            ['slug' => 'produk-'.$index],
            [
                'category_id' => $category->getKey(),
                'name' => $meta['name'],
                'short_description' => null, // belum dikonfirmasi pemilik
                'status' => ProductStatus::Active,
                'is_featured' => $index <= 3,
                'published_at' => now()->subDay(),
                'meta_description' => null,
                'badge' => $meta['badge'],
                'badge_tone' => BadgeTone::from($meta['badge_tone']),
            ],
        );

        // SKU default. Harga 0 & stok 0: TIDAK dikarang.
        $product->skus()->updateOrCreate(
            ['code' => 'PMG-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT)],
            [
                'price' => 0,
                'on_hand' => 0,
                'is_active' => true,
            ],
        );

        return $product;
    }

    /**
     * Pasang satu foto produk.
     *
     * Hanya varian TERLEBAR yang dipasang. Versi drive sudah menyediakan
     * 480/800/1080 + thumbnail, dan `next/image` yang akan memperkecil
     * sendiri sesuai layar. Menyimpan ketiganya sebagai tiga baris
     * `product_media` akan membuat galeri produk menampilkan foto yang
     * SAMA tiga kali.
     *
     * @param  array<string, mixed>  $variant
     */
    private function attachMedia(Product $product, array $variant, string $name): void
    {
        $product->media()->delete();

        ProductMedia::create([
            'product_id' => $product->getKey(),
            // Di produksi ini `s3` (PRD §3.2). Di dev memakai public disk.
            'disk' => config('filesystems.default') === 's3' ? 's3' : 'public',
            'path' => $variant['path'],
            'alt_text' => $name.' — foto produk dari Palo Mountain Goods.',
            'sort_order' => 0,
        ]);

        $this->command?->line(sprintf(
            '  %-22s %s (%dx%d)',
            $name,
            $variant['path'],
            (int) $variant['width'],
            (int) $variant['height'],
        ));
    }

    /**
     * Buat varian ukuran bila produk punya lebih dari satu ukuran.
     *
     * SKU per kombinasi varian dibuat dengan harga & stok 0 — belum
     * dikonfirmasi.
     *
     * @param  array<int, string>  $sizes
     */
    private function attachVariants(Product $product, array $sizes): void
    {
        if ($sizes === []) {
            return; // tidak ada informasi ukuran sama sekali
        }

        $sizeAttribute = Attribute::firstOrCreate(
            ['slug' => 'ukuran'],
            ['name' => 'Ukuran', 'is_filterable' => true, 'sort_order' => 1],
        );

        /*
         * SATU ukuran: pakai SKU default yang sudah ada, jangan buat SKU baru.
         *
         * Kalau tetap membuat varian+SKU, produk bersize tunggal akan punya
         * dua SKU untuk ukuran yang sama — dan `productSizes()` tetap
         * mengembalikan satu label, jadi chip ukurannya benar tapi datanya
         * dobel.
         */
        if (count($sizes) === 1) {
            $value = AttributeValue::firstOrCreate(
                ['attribute_id' => $sizeAttribute->getKey(), 'slug' => strtolower($sizes[0])],
                ['value' => $sizes[0], 'sort_order' => 1],
            );

            $variant = ProductVariant::firstOrCreate(
                ['product_id' => $product->getKey(), 'name' => $sizes[0]],
                ['is_default' => true],
            );
            $variant->attributeValues()->syncWithoutDetaching([$value->getKey()]);

            // SKU default diambil alih oleh varian ini — tidak menambah SKU.
            $defaultSku = $product->skus()->whereNull('product_variant_id')->first();
            $defaultSku?->update(['product_variant_id' => $variant->getKey()]);

            return;
        }

        foreach ($sizes as $size) {
            $value = AttributeValue::firstOrCreate(
                ['attribute_id' => $sizeAttribute->getKey(), 'slug' => strtolower($size)],
                ['value' => $size, 'sort_order' => array_search($size, $sizes, true)],
            );

            $variant = ProductVariant::firstOrCreate(
                ['product_id' => $product->getKey(), 'name' => $size],
                ['is_default' => false],
            );

            $variant->attributeValues()->syncWithoutDetaching([$value->getKey()]);

            // `product_id` HARUS diisi eksplisit. Relasi `ProductVariant::skus()`
            // hanya mengisi `product_variant_id` — kolom `product_id` punya
            // constraint NOT NULL karena katalog perlu tahu produk induknya
            // tanpa harus lewat relasi varian.
            //
            // Kalau tidak diisi, error-nya muncul sebagai "null value in column
            // product_id" yang tidak mengarah ke baris seeder mana penyebabnya.
            $variant->skus()->updateOrCreate(
                ['code' => $product->skus()->first()?->code.'-'.strtoupper($size)],
                [
                    'product_id' => $product->getKey(),
                    'price' => 0,
                    'on_hand' => 0,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Ambil varian gambar paling lebar dari sekumpulan varian.
     *
     * ===================================================================
     *  KENAPA HARUS VARIAN TERLEBAR, BUKAN YANG PERTAMA
     * ===================================================================
     * Database hanya menyimpan SATU path, dan path itu menjadi sumber
     * bagi optimizer `/_next/image` di Next.js. Next.js mengambil file itu
     * lalu MENURUNKAN ukurannya sesuai `sizes` perangkat.
     *
     *   - Varian TERLEBAR -> desktop 1080px, ponsel 480px. Tajam semua.
     *   - Varian TERKECIL -> hero 1400px dibangun dari foto 480px. Kabur.
     *
     * Yang kedua menghasilkan halaman 200 tanpa error, hanya foto yang
     * terasa murah — respons yang paling mahal dicari.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>|null
     */
    private function varianTerlebar(array $variants): ?array
    {
        $terlebar = null;

        foreach ($variants as $variant) {
            if (! isset($variant['path'])) {
                continue;
            }

            $lebar = (int) ($variant['width'] ?? 0);

            if ($terlebar === null || $lebar > (int) ($terlebar['width'] ?? 0)) {
                $terlebar = $variant;
            }
        }

        return $terlebar;
    }

    /**
     * Banner untuk hero beranda.
     *
     * ===================================================================
     *  BACA DARI MANIFEST LAIN, BUKAN DARI drive_asset
     * ===================================================================
     * Semua foto di `drive_asset/` berorientasi potret (1080x1350),
     * sedangkan hero adalah blok full-bleed landscape. Memaksanya berarti
     * memotong subjek utama — dada tempat desain kaos terlihat — supaya
     * desainnya justru tidak terbaca.
     *
     * Sumber banner tetap `banner/banner1.png` (1672x941), diproses oleh
     * `scripts/prepare_images.py` dan tercatat di `assets_img/manifest.json`.
     *
     * @param  array<string, mixed>  $manifest  Manifest drive_asset; TIDAK dipakai di sini.
     */
    private function makeBanners(array $manifest): void
    {
        $bannerManifestPath = base_path('../assets_img/manifest.json');
        $bannerManifest = File::exists($bannerManifestPath)
            ? (json_decode(File::get($bannerManifestPath), true) ?: [])
            : [];

        $fotoBanner = null;
        foreach (($bannerManifest['banners'] ?? []) as $entry) {
            if ((int) ($entry['slide'] ?? 0) === 1) {
                $fotoBanner = $this->varianTerlebar($entry['variants'] ?? []);
                break;
            }
        }

        $banners = [
            [
                'image' => $fotoBanner,
                'alt' => 'Tiga orang mengenakan koleksi busana Palo Mountain Goods di dekat airrai Senaru.',
                'title' => 'Stories from the Land of Rinjani',
                'eyebrow' => 'Koleksi Busana & Cenderamata',
                'subtitle' => 'Dirancang di lembah Senaru dengan keheningan kawah Segara Anak dan keteduhan hutan tropis Lombok.',
                'body' => null,
                'stamp_left' => 'FORM. 01 — RINJANI SERIES',
                'stamp_right' => 'SENARU / 601 MDPL',
                'cta_label' => 'Lihat Katalog',
                'cta_url' => '/produk',
                'cta2_label' => 'Cerita Senaru',
                'cta2_url' => '/cerita',
                'theme' => 'light',
                'overlay_opacity' => 60,
            ],
        ];

        // Hapus banner lama supaya seeder bisa dijalankan berulang tanpa
        // menumpuk duplikat. Dibatasi ke path banner, jadi banner buatan
        // admin yang memakai foto produk tidak ikut terhapus.
        Banner::where('image_path', 'like', 'banners/banner-%')->delete();

        $position = 0;
        foreach ($banners as $data) {
            $variant = $data['image'];

            if ($variant === null) {
                $this->command?->warn(
                    '  Banner dilewati: banner/banner1.png belum diproses. Jalankan: python3 scripts/prepare_images.py',
                );

                continue;
            }

            Banner::create([
                'title' => $data['title'],
                'subtitle' => $data['subtitle'],
                'body' => $data['body'],
                'stamp_left' => $data['stamp_left'],
                'stamp_right' => $data['stamp_right'],
                'eyebrow' => $data['eyebrow'],
                'cta_label' => $data['cta_label'],
                'cta_url' => $data['cta_url'],
                'cta2_label' => $data['cta2_label'],
                'cta2_url' => $data['cta2_url'],
                'disk' => config('filesystems.default') === 's3' ? 's3' : 'public',
                'image_path' => $variant['path'],
                'image_alt' => $data['alt'],
                'theme' => $data['theme'],
                'overlay_opacity' => $data['overlay_opacity'],
                'is_active' => true,
                'sort_order' => $position,
            ]);

            $position++;
        }

        $this->command?->line(sprintf('  %d banner hero dibuat.', $position));
    }

    private function registerBrandMedia(): void
    {
        AppSetting::put('brand.logo_path', 'brand/logo-150.webp', 'general', 'Logo Brand');
    }
}
