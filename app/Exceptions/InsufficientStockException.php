<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStockException extends Exception
{
    public function __construct(
        string $message,
        public ?string $skuCode = null,
        public int $available = 0,
        public int $requested = 0
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
            'error_code' => 'stock_unavailable',
            'sku_code' => $this->skuCode,
            'available_stock' => $this->available,
            'requested_quantity' => $this->requested,
        ], 409);
    }
}
