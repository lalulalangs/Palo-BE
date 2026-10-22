<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\DokuWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly DokuWebhookService $dokuWebhookService
    ) {}

    /**
     * HTTP Notification dari DOKU.
     *
     * Endpoint sengaja tanpa `auth:sanctum`: keaslian request diverifikasi
     * lewat signature HMAC-SHA256 (`Client-Id`, `Request-Id`,
     * `Request-Timestamp`, `Signature`).
     */
    public function doku(Request $request): JsonResponse
    {
        return $this->dokuWebhookService->handle($request);
    }
}
