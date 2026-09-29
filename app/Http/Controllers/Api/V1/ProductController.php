<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Http\Resources\Api\V1\ProductListResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected CatalogCacheService $catalogCache
    ) {}

    /**
     * Display a listing of active products with filters, search, and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->only([
            'category',
            'search',
            'min_price',
            'max_price',
            'is_featured',
            'in_stock',
            'sort',
            'per_page',
            'page',
        ]);

        $cachedResponse = $this->catalogCache->rememberProductList($params, function () use ($request): array {
            $query = Product::where('is_active', true)
                ->with([
                    'category',
                    'thumbnail',
                    'variants.skus',
                ]);

            // Filter by Category slug (including sub-categories)
            if ($categorySlug = $request->input('category')) {
                $category = Category::where('slug', $categorySlug)->with('children')->first();

                if ($category) {
                    $categoryIds = array_merge([$category->id], $category->children->pluck('id')->all());
                    $query->whereIn('category_id', $categoryIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }

            // Search in Name or Description
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Price range filter
            if ($request->filled('min_price')) {
                $query->where('base_price', '>=', (float) $request->input('min_price'));
            }

            if ($request->filled('max_price')) {
                $query->where('base_price', '<=', (float) $request->input('max_price'));
            }

            // Featured flag filter
            if ($request->has('is_featured')) {
                $query->where('is_featured', filter_var($request->input('is_featured'), FILTER_VALIDATE_BOOLEAN));
            }

            // In-stock filter
            if ($request->boolean('in_stock')) {
                $query->whereHas('variants.skus', function (Builder $sq): void {
                    $sq->where('is_active', true)->where('stock', '>', 0);
                });
            }

            // Sorting
            match ($request->input('sort')) {
                'price_asc' => $query->orderBy('base_price', 'asc'),
                'price_desc' => $query->orderBy('base_price', 'desc'),
                default => $query->orderBy('created_at', 'desc'),
            };

            // Pagination (default 12)
            $perPage = min(max((int) $request->input('per_page', 12), 1), 100);
            $products = $query->paginate($perPage);

            return [
                'data' => ProductListResource::collection($products->items())->resolve(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'has_more' => $products->hasMorePages(),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar produk berhasil diambil',
            'data' => $cachedResponse['data'],
            'pagination' => $cachedResponse['pagination'],
        ]);
    }

    /**
     * Display the specified product with media, variants, and stock information.
     */
    public function show(string $slug): JsonResponse
    {
        $cachedData = $this->catalogCache->rememberProductDetail($slug, function () use ($slug): ?array {
            $product = Product::where('slug', $slug)
                ->where('is_active', true)
                ->with([
                    'category.parent',
                    'media',
                    'variants.skus',
                ])
                ->first();

            if (! $product) {
                return null;
            }

            return (new ProductDetailResource($product))->resolve();
        });

        if (! $cachedData) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan atau tidak aktif',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail produk berhasil diambil',
            'data' => $cachedData,
        ]);
    }
}
