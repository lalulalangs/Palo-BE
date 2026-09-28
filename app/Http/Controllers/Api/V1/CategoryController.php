<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Display a nested tree listing of root categories and their sub-categories.
     */
    public function index(): JsonResponse
    {
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

        return response()->json([
            'success' => true,
            'message' => 'Daftar kategori berhasil diambil',
            'data' => CategoryResource::collection($categories),
        ]);
    }
}
