<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockLockExpiredException extends Exception
{
    public function __construct(
        string $message = 'Masa tahan stok pesanan telah berakhir dan stok tidak lagi mencukupi.'
    ) {
        parent::__construct($message, 409);
    }

    /**
     * Render exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => 'stock_lock_expired',
        ], 409);
    }
}
