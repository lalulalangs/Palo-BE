<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceChangedException extends Exception
{
    /**
     * @param  array<int, array{sku_id: int, sku_code: string, product_name: string, old_price: float, new_price: float, quantity: int}>  $changes
     */
    public function __construct(
        public array $changes,
        public float $newGrandTotal,
        string $message = 'Harga produk berubah sejak keranjang dibuka. Tinjau perubahannya sebelum melanjutkan.'
    ) {
        parent::__construct($message, 409);
    }

    /**
     * Render exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'price_changed',
            'changes' => $this->changes,
            'new_grand_total' => $this->newGrandTotal,
        ], 409);
    }
}
