<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

class ImportCatalogProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:import 
                            {path? : Path ke direktori berisi folder produk} 
                            {--stock=25 : Default stock per SKU} 
                            {--force : Re-import / overwrite jika produk sudah ada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate katalog produk nyata dari direktori JSON & gambar ke database staging/lokal';

    /**
     * Execute the console command.
     */
    public function handle(ImageOptimizer $imageOptimizer): int
    {
        $basePath = $this->argument('path')
            ?: (File::isDirectory('/home/lalulalang/Documents/palorinjani/upload-to-db')
                ? '/home/lalulalang/Documents/palorinjani/upload-to-db'
                : base_path('../upload-to-db'));

        $defaultStock = max(0, (int) $this->option('stock'));
        $force = (bool) $this->option('force');

        if (! File::isDirectory($basePath)) {
            $this->error("Direktori tidak ditemukan: {$basePath}");
            return self::FAILURE;
        }

        $this->info("Memulai proses migrasi katalog produk dari: {$basePath}");
        $this->info("Stok default per SKU: {$defaultStock}");
        $this->info("Mode force: " . ($force ? 'AKTIF (akan me-replace produk yang ada)' : 'NON-AKTIF'));

        // 1. Pastikan kategori 'Apparel' dengan slug 'apparel' ada
        $category = Category::firstOrCreate(
            ['slug' => 'apparel'],
            ['name' => 'Apparel']
        );
        $this->line("Kategori Apparel ID: {$category->id}");

        // 2. Baca subdirektori (folder 1..18) dan urutkan secara numerik
        $directories = collect(File::directories($basePath))
            ->sortBy(function (string $dirPath) {
                $dirName = basename($dirPath);
                return is_numeric($dirName) ? (int) $dirName : $dirName;
            })
            ->values();

        if ($directories->isEmpty()) {
            $this->warn("Tidak ada sub-folder produk ditemukan di direktori: {$basePath}");
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$directories->count()} folder produk.");

        $successCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($directories as $dirPath) {
            $folderName = basename($dirPath);
            $jsonFile = $dirPath . '/data.json';

            if (! File::exists($jsonFile)) {
                $this->warn("[Folder {$folderName}] Melewati: file data.json tidak ditemukan.");
                $skippedCount++;
                continue;
            }

            try {
                $rawContent = File::get($jsonFile);
                $data = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $this->error("[Folder {$folderName}] Gagal parsing data.json: " . $e->getMessage());
                $failedCount++;
                continue;
            }

            $productName = trim($data['product_name'] ?? '');
            if ($productName === '') {
                $this->warn("[Folder {$folderName}] Melewati: nama produk kosong.");
                $skippedCount++;
                continue;
            }

            $slug = Str::slug($productName);

            // Cek apakah produk sudah ada
            $existingProduct = Product::where('slug', $slug)->first();

            if ($existingProduct && ! $force) {
                $this->line("[Folder {$folderName}] Produk '{$productName}' sudah ada. Dilewati (gunakan --force untuk overwrite).");
                $skippedCount++;
                continue;
            }

            $this->info("[Folder {$folderName}] Memproses produk: {$productName}");

            DB::beginTransaction();
            try {
                // Format description dengan bullet points
                $formattedDescription = $this->formatDescription($data['details'] ?? []);

                // Hapus varian & media lama jika overwrite
                if ($existingProduct && $force) {
                    foreach ($existingProduct->media as $oldMedia) {
                        if ($oldMedia->url) {
                            Storage::disk('public')->delete($oldMedia->url);
                        }
                        $oldMedia->delete();
                    }

                    foreach ($existingProduct->variants as $variant) {
                        $variant->skus()->delete();
                        $variant->delete();
                    }

                    $product = $existingProduct;
                    $product->update([
                        'category_id' => $category->id,
                        'name' => $productName,
                        'slug' => $slug,
                        'description' => $formattedDescription,
                        'base_price' => (float) ($data['price'] ?? 0),
                        'is_active' => true,
                        'is_featured' => false,
                    ]);
                } else {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'name' => $productName,
                        'slug' => $slug,
                        'description' => $formattedDescription,
                        'base_price' => (float) ($data['price'] ?? 0),
                        'is_active' => true,
                        'is_featured' => false,
                    ]);
                }

                // Buat Varian & SKU untuk available_sizes
                $color = trim($data['color'] ?? 'Default');
                $sizes = $data['available_sizes'] ?? ['S', 'M', 'L', 'XL'];

                foreach ($sizes as $size) {
                    $size = trim((string) $size);
                    $variantName = "{$color} / Size {$size}";

                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'name' => $variantName,
                        'attributes' => [
                            'color' => $color,
                            'size' => $size,
                        ],
                    ]);

                    $skuCode = 'PR-' . strtoupper(Str::slug($product->slug . '-' . $size));

                    Sku::create([
                        'variant_id' => $variant->id,
                        'sku_code' => $skuCode,
                        'price_override' => null,
                        'stock' => $defaultStock,
                        'weight_grams' => 350,
                        'is_active' => true,
                    ]);
                }

                // Proses Gambar Produk
                $this->processProductImages($product, $dirPath, $imageOptimizer);

                DB::commit();
                $successCount++;
                $this->info("✓ Berhasil mengimpor: {$productName}");
            } catch (Throwable $e) {
                DB::rollBack();
                $failedCount++;
                $this->error("✗ Gagal mengimpor [Folder {$folderName}] {$productName}: " . $e->getMessage());
                Log::error('catalog.import_failed', [
                    'folder' => $folderName,
                    'product_name' => $productName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // 3. Bersihkan cache catalog categories
        Cache::forget('catalog:categories');
        $this->line("Cache 'catalog:categories' dibersihkan.");

        $this->newLine();
        $this->info("=== Ringkasan Migrasi Produk ===");
        $this->info("Total folder ditemukan : {$directories->count()}");
        $this->info("Berhasil diimpor       : {$successCount}");
        $this->info("Dilewati               : {$skippedCount}");
        $this->info("Gagal                  : {$failedCount}");

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Format field description produk dari details menjadi bullet points.
     *
     * @param array<string, mixed> $details
     */
    private function formatDescription(array $details): string
    {
        $bullets = [];

        if (! empty($details['material'])) {
            $bullets[] = "• Material: {$details['material']}";
        }
        if (! empty($details['weight'])) {
            $bullets[] = "• Weight: {$details['weight']}";
        }
        if (! empty($details['fit'])) {
            $bullets[] = "• Fit: {$details['fit']}";
        }
        if (! empty($details['finish'])) {
            $bullets[] = "• Finish: {$details['finish']}";
        }

        $mainDescription = trim($details['description'] ?? '');

        if (! empty($bullets)) {
            return ($mainDescription !== '' ? $mainDescription . "\n\n" : '') . implode("\n", $bullets);
        }

        return $mainDescription;
    }

    /**
     * Cari berkas gambar di folder, urutkan (depan = 1, belakang = 2, sisanya 3+),
     * optimasi ke WebP dan simpan ke product_media.
     */
    private function processProductImages(Product $product, string $dirPath, ImageOptimizer $imageOptimizer): void
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files = File::files($dirPath);

        $imageFiles = collect($files)->filter(function (SplFileInfo $file) use ($imageExtensions) {
            return in_array(strtolower($file->getExtension()), $imageExtensions, true);
        });

        if ($imageFiles->isEmpty()) {
            return;
        }

        $sortedImages = $imageFiles->sort(function (SplFileInfo $a, SplFileInfo $b) {
            $priorityA = $this->getImageSortPriority($a->getFilename());
            $priorityB = $this->getImageSortPriority($b->getFilename());

            if ($priorityA === $priorityB) {
                return strcmp($a->getFilename(), $b->getFilename());
            }

            return $priorityA <=> $priorityB;
        })->values();

        $sortOrder = 1;
        foreach ($sortedImages as $file) {
            $filePath = $file->getRealPath();
            $mimeType = @mime_content_type($filePath) ?: 'image/jpeg';

            $uploadedFile = new UploadedFile(
                $filePath,
                $file->getFilename(),
                $mimeType,
                null,
                true // flag test mode agar melewati pengecekan is_uploaded_file()
            );

            $storedPath = $imageOptimizer->optimize($uploadedFile, 'products', 'public');

            ProductMedia::create([
                'product_id' => $product->id,
                'url' => $storedPath,
                'type' => 'image',
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * Tentukan prioritas pengurutan gambar berdasarkan nama berkas.
     * Tampak depan / depan => prioritas 1
     * Tampak belakang / belakang => prioritas 2
     * Berkas lainnya => prioritas 3
     */
    private function getImageSortPriority(string $filename): int
    {
        $name = strtolower($filename);

        if (str_contains($name, 'tampak-depan') || str_contains($name, 'depan') || str_contains($name, 'front')) {
            return 1;
        }

        if (str_contains($name, 'tampak-belakang') || str_contains($name, 'belakang') || str_contains($name, 'back')) {
            return 2;
        }

        return 3;
    }
}
