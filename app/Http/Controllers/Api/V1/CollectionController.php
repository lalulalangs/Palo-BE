<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CollectionDetailResource;
use App\Http\Resources\Api\V1\CollectionListResource;
use App\Http\Resources\Api\V1\ProductListResource;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    /**
     * Display a listing of active collections.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Collection::active()
            ->withCount(['products' => function (Builder $pq): void {
                $pq->where('products.is_active', true);
            }])
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc');

        if ($request->has('featured')) {
            $query->where('is_featured', filter_var($request->input('featured'), FILTER_VALIDATE_BOOLEAN));
        }

        $collections = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar koleksi berhasil diambil',
            'data' => CollectionListResource::collection($collections),
        ]);
    }

    /**
     * Display the specified collection with curated active products.
     */
    public function show(string $slug, Request $request): JsonResponse
    {
        $collection = Collection::where('slug', $slug)
            ->active()
            ->first();

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Koleksi tidak ditemukan atau tidak aktif',
                'data' => null,
            ], 404);
        }

        $perPage = min(max((int) $request->input('per_page', 12), 1), 100);

        $products = $collection->products()
            ->where('products.is_active', true)
            ->with([
                'category',
                'thumbnail',
                'variants.skus',
            ])
            ->orderByPivot('sort_order', 'asc')
            ->orderBy('products.created_at', 'desc')
            ->paginate($perPage);

        $collectionData = (new CollectionDetailResource($collection))->toArray($request);
        $collectionData['products'] = ProductListResource::collection($products->items());

        return response()->json([
            'success' => true,
            'message' => 'Detail koleksi berhasil diambil',
            'data' => $collectionData,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }
}
