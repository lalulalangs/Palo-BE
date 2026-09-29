<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Services\CatalogCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        protected CatalogCacheService $catalogCache
    ) {}

    /**
     * Display a nested tree listing of root categories and their sub-categories.
     */
    public function index(): JsonResponse
    {
        $cachedData = $this->catalogCache->rememberCategories(function (): array {
            $categories = Category::whereNull('parent_id')
                ->with([
                    'children' => function ($q): void {
                        $q->withCount(['products' => function (Builder $pq): void {
                            $pq->where('is_active', true);
                        }]);
                    },
                ])
                ->withCount(['products' => function (Builder $pq): void {
                    $pq->where('is_active', true);
                }])
                ->orderBy('name', 'asc')
                ->get();

            return CategoryResource::collection($categories)->resolve();
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar kategori berhasil diambil',
            'data' => $cachedData,
        ]);
    }
}
