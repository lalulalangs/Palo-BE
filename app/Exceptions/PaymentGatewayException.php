<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kegagalan integrasi payment gateway (DOKU) yang aman ditampilkan ke customer.
 *
 * Pesan tidak pernah memuat kredensial maupun isi respons mentah gateway;
 * detail teknis hanya dikirim ke log oleh pemanggil.
 */
class PaymentGatewayException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public string $errorCode = 'payment_gateway_error',
        public int $status = 502,
        public array $context = []
    ) {
        parent::__construct($message, $status);
    }

    public static function notConfigured(): self
    {
        return new self(
            message: 'Pembayaran online belum tersedia saat ini. Silakan pilih COD atau hubungi Customer Service kami.',
            errorCode: 'payment_unavailable',
            status: 503,
        );
    }

    public static function rejected(string $invoiceNumber, array $details = []): self
    {
        return new self(
            message: 'Transaksi pembayaran gagal dibuat. Silakan coba lagi beberapa saat lagi.',
            errorCode: 'payment_gateway_error',
            status: 502,
            context: ['invoice_number' => $invoiceNumber, 'gateway_details' => $details],
        );
    }

    public static function unreachable(): self
    {
        return new self(
            message: 'Gagal menghubungi penyedia pembayaran. Silakan coba lagi beberapa saat lagi.',
            errorCode: 'payment_gateway_unreachable',
            status: 502,
        );
    }

    public static function invalidResponse(string $invoiceNumber): self
    {
        return new self(
            message: 'Respons penyedia pembayaran tidak valid. Silakan coba lagi beberapa saat lagi.',
            errorCode: 'payment_gateway_error',
            status: 502,
            context: ['invoice_number' => $invoiceNumber],
        );
    }

    public static function invalidAmount(): self
    {
        return new self(
            message: 'Nominal pesanan tidak valid untuk pembayaran online.',
            errorCode: 'payment_invalid_amount',
            status: 422,
        );
    }

    public static function invalidWebhook(): self
    {
        return new self(
            message: 'Payload notifikasi pembayaran tidak valid.',
            errorCode: 'payment_invalid_webhook',
            status: 400,
        );
    }

    /**
     * Render exception menjadi response JSON standar API.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->status);
    }
}
