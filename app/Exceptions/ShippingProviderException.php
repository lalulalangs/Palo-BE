<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kegagalan integrasi RajaOngkir (Shipping Cost API) yang aman ditampilkan
 * ke customer.
 *
 * Pesan tidak pernah memuat API key maupun body respons mentah; detail
 * teknis hanya dikirim ke log oleh pemanggil.
 */
class ShippingProviderException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public string $errorCode = 'shipping_provider_error',
        public int $status = 502,
        public bool $transient = false,
        public array $context = []
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Gagal menghubungi penyedia (timeout/connection). Pemanggil masih boleh
     * memakai cache basi untuk tampilan, tetapi tidak untuk checkout.
     */
    public static function unreachable(string $context, string $detail = ''): self
    {
        return new self(
            message: 'Gagal menghubungi layanan kurir. Silakan coba beberapa saat lagi.',
            errorCode: 'shipping_provider_unreachable',
            status: 503,
            transient: true,
            context: ['context' => $context, 'detail' => $detail],
        );
    }

    /**
     * Penyedia merespons tetapi menolak permintaan. 5xx dari penyedia masih
     * dianggap sementara; 4xx menandakan bug pada parameter yang kita kirim.
     */
    public static function rejected(string $context, int $httpStatus, string $detail, int $metaCode = 0): self
    {
        return new self(
            message: 'Layanan kurir menolak permintaan ongkir. Silakan coba beberapa saat lagi.',
            errorCode: 'shipping_provider_rejected',
            // 5xx penyedia = layanan sedang tidak tersedia; 4xx = bug parameter kita.
            status: $httpStatus >= 500 ? 503 : 502,
            transient: $httpStatus >= 500,
            context: [
                'context' => $context,
                'http_status' => $httpStatus,
                'meta_code' => $metaCode,
                'detail' => $detail,
            ],
        );
    }

    /**
     * Respons tidak dapat dibaca sebagai JSON objek.
     */
    public static function invalidResponse(string $context, int $httpStatus): self
    {
        return new self(
            message: 'Respons layanan kurir tidak valid. Silakan coba beberapa saat lagi.',
            errorCode: 'shipping_provider_invalid_response',
            status: $httpStatus >= 500 ? 503 : 502,
            transient: $httpStatus >= 500,
            context: ['context' => $context, 'http_status' => $httpStatus],
        );
    }

    /**
     * API key / base URL belum diisi di environment.
     */
    public static function notConfigured(): self
    {
        return new self(
            message: 'Layanan ongkir belum dikonfigurasi. Silakan hubungi Customer Service kami.',
            errorCode: 'shipping_not_configured',
            status: 503,
        );
    }

    /**
     * Kota/kecamatan tujuan tidak dikenali RajaOngkir.
     */
    public static function destinationNotFound(string $keyword): self
    {
        return new self(
            message: 'Tujuan pengiriman tidak ditemukan. Silakan pilih kota/kecamatan dari daftar.',
            errorCode: 'shipping_destination_not_found',
            status: 422,
            context: ['keyword' => $keyword],
        );
    }

    /**
     * Berat total melebihi batas pengiriman yang dilayani.
     */
    public static function weightTooHeavy(int $grams, int $maxGrams): self
    {
        return new self(
            message: 'Berat pesanan melebihi batas pengiriman. Silakan hubungi Customer Service kami.',
            errorCode: 'shipping_weight_too_heavy',
            status: 422,
            context: ['weight_grams' => $grams, 'max_weight_grams' => $maxGrams],
        );
    }

    public function isTransient(): bool
    {
        return $this->transient;
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
