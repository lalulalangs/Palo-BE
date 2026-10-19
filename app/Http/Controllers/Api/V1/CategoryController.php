<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    private const CACHE_KEY = 'catalog:categories';

    private const CACHE_TTL_SECONDS = 600;

    /**
     * Display a nested tree listing of root categories and their sub-categories.
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
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
                ->limit(100)
                ->get();

            return CategoryResource::collection($categories)->resolve();
        });

        return $this->respond($data, 'Daftar kategori berhasil diambil');
    }
}
